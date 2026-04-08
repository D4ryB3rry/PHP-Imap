<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Exception\ProtocolException;
use D4ry\ImapClient\Protocol\StreamingFetchState;

class ResponseParser
{
    /**
     * One-shot literal sink. When set, the next `{N}` literal encountered by
     * {@see readFullLine()} is streamed straight from the socket into this
     * resource instead of being buffered into a PHP string. The slot is
     * cleared as soon as it is consumed so subsequent literals fall back to
     * the normal buffered path.
     *
     * Used by {@see Transceiver::commandWithLiteralSink()} to fetch large
     * IMAP literals (attachment bodies) without ever materializing them in
     * PHP heap.
     *
     * @var resource|null
     */
    private $literalSink = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * @param resource|null $sink
     */
    public function setNextLiteralSink($sink): void
    {
        $this->literalSink = $sink;
    }

    public function readGreeting(): UntaggedResponse
    {
        $line = $this->readFullLine();

        if (!str_starts_with($line, '* ')) {
            throw new ProtocolException('Expected server greeting, got: ' . $line);
        }

        $rest = substr($line, 2);

        if (str_starts_with($rest, 'OK')) {
            return new UntaggedResponse('OK', $this->extractResponseData($rest, 'OK'), $line);
        }

        if (str_starts_with($rest, 'PREAUTH')) {
            return new UntaggedResponse('PREAUTH', $this->extractResponseData($rest, 'PREAUTH'), $line);
        }

        if (str_starts_with($rest, 'BYE')) {
            throw new ProtocolException('Server rejected connection: ' . $line);
        }

        return new UntaggedResponse('OK', $rest, $line);
    }

    public function readResponse(string $expectedTag): Response
    {
        $untagged = [];

        while (true) {
            $line = $this->readFullLine();

            if (str_starts_with($line, '* ')) {
                $untagged[] = $this->parseUntaggedLine($line);
                continue;
            }

            if ($line === '+' || str_starts_with($line, '+ ')) {
                return new Response(
                    status: ResponseStatus::Ok,
                    tag: '+',
                    text: $line === '+' ? '' : trim(substr($line, 2)),
                    untagged: $untagged,
                );
            }

            if (str_starts_with($line, $expectedTag . ' ')) {
                return $this->parseTaggedLine($line, $expectedTag, $untagged);
            }

            $untagged[] = new UntaggedResponse('UNKNOWN', null, $line);
        }
    }

    /**
     * Reads exactly one protocol item from the socket and dispatches it into
     * a {@see StreamingFetchState}. FETCH untagged responses go onto the
     * fetch queue (for the streaming generator to drain), other untagged
     * lines are buffered, and a tagged line matching the state's tag closes
     * the stream.
     *
     * Used by both the streaming generator (one item per pull) and by
     * {@see Transceiver::drainStreamingFetch()} (loop until completed) so a
     * nested command can finish reading the outer FETCH before issuing its
     * own command on the wire.
     */
    public function readNextStreamingItem(StreamingFetchState $state): void
    {
        $line = $this->readFullLine();

        if (str_starts_with($line, '* ')) {
            $parsed = $this->parseUntaggedLine($line);

            if ($parsed->type === 'FETCH') {
                $state->fetchQueue[] = $parsed;
            } else {
                $state->otherUntagged[] = $parsed;
            }

            return;
        }

        if (str_starts_with($line, $state->tag . ' ')) {
            $state->finalResponse = $this->parseTaggedLine($line, $state->tag, $state->otherUntagged);
            // Untagged ownership has moved into finalResponse->untagged.
            $state->otherUntagged = [];
            $state->completed = true;

            return;
        }

        // Continuations are not expected mid-FETCH; preserve as unknown so
        // the surrounding code can still inspect it after the fact.
        $state->otherUntagged[] = new UntaggedResponse('UNKNOWN', null, $line);
    }

    public function readContinuation(): string
    {
        $line = $this->readFullLine();

        if ($line === '+') {
            return '';
        }

        if (!str_starts_with($line, '+ ')) {
            throw new ProtocolException('Expected continuation, got: ' . $line);
        }

        return trim(substr($line, 2));
    }

    private function readFullLine(): string
    {
        $parts = [];
        $line = $this->connection->readLine();

        while (preg_match('/\{(\d+)\+?\}\s*$/', $line, $matches)) {
            $literalSize = (int) $matches[1];

            if ($this->literalSink !== null) {
                // Sink mode: stream the literal straight from socket to the
                // sink in 8 KiB chunks. We then rewrite the {N} framing in
                // the line to {0} so FetchResponseParser parses an empty
                // literal value for this section — the real bytes already
                // live in the sink. This is the streaming path that lets
                // Attachment::save() avoid holding the encoded body in PHP
                // heap. One-shot: the slot is cleared after use so any
                // subsequent literal in the same response (e.g. an unrelated
                // FETCH untagged update) falls back to buffered reading.
                $sink = $this->literalSink;
                $this->literalSink = null;

                $this->connection->streamBytesTo($sink, $literalSize);

                $line = preg_replace('/\{\d+\+?\}(\s*)$/', '{0}$1', $line);
                $parts[] = $line;
                // No literal data appended — readLiteral() will substr 0 bytes.
            } else {
                $literalData = $this->connection->readBytes($literalSize);

                // Preserve {N}\r\n<data> format so FetchResponseParser::readLiteral() can handle it
                $parts[] = $line;
                $parts[] = $literalData;
            }

            $line = $this->connection->readLine();
        }

        $parts[] = $line;

        return rtrim(implode('', $parts), "\r\n");
    }

    private function parseUntaggedLine(string $line): UntaggedResponse
    {
        $rest = substr($line, 2);

        if (preg_match('/^(\d+)\s+(\w+)(.*)\z/s', $rest, $matches)) {
            $number = (int) $matches[1];
            $type = strtoupper($matches[2]);
            $data = trim($matches[3]);

            if ($type === 'FETCH') {
                $data = $this->parseFetchData($data, $number);
            } else {
                $data = ['number' => $number, 'data' => $data];
            }

            return new UntaggedResponse($type, $data, $line);
        }

        if (preg_match('/^(OK|NO|BAD|BYE|PREAUTH)\s+(.*)$/i', $rest, $matches)) {
            $type = strtoupper($matches[1]);
            $text = $matches[2];
            $responseCode = null;

            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $text, $codeMatch)) {
                $responseCode = $codeMatch[1];
                $text = $codeMatch[2];
            }

            return new UntaggedResponse($type, [
                'text' => $text,
                'code' => $responseCode,
            ], $line);
        }

        if (preg_match('/^(CAPABILITY|FLAGS|LIST|LSUB|STATUS|SEARCH|SORT|THREAD|NAMESPACE|ID|ENABLED)\s+(.*)$/i', $rest, $matches)) {
            $type = strtoupper($matches[1]);
            $data = trim($matches[2]);

            return new UntaggedResponse($type, $this->parseUntaggedData($type, $data), $line);
        }

        if (preg_match('/^(\w+)\s*(.*)$/', $rest, $matches)) {
            return new UntaggedResponse(strtoupper($matches[1]), trim($matches[2]), $line);
        }

        return new UntaggedResponse('UNKNOWN', $rest, $line);
    }

    /**
     * @param UntaggedResponse[] $untagged
     */
    private function parseTaggedLine(string $line, string $tag, array $untagged): Response
    {
        $rest = substr($line, strlen($tag) + 1);

        if (preg_match('/^(OK|NO|BAD)\s+(.*)$/i', $rest, $matches)) {
            $status = ResponseStatus::from(strtoupper($matches[1]));
            $text = $matches[2];
            $responseCode = null;

            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $text, $codeMatch)) {
                $responseCode = $codeMatch[1];
                $text = $codeMatch[2];
            }

            return new Response(
                status: $status,
                tag: $tag,
                text: $text,
                untagged: $untagged,
                responseCode: $responseCode,
            );
        }

        throw new ProtocolException('Unable to parse tagged response: ' . $line);
    }

    private function parseUntaggedData(string $type, string $data): mixed
    {
        return match ($type) {
            'CAPABILITY' => $this->parseCapabilities($data),
            'FLAGS' => $this->parseParenthesizedList($data),
            'LIST', 'LSUB' => $this->parseListResponse($data),
            'STATUS' => $this->parseStatusResponse($data),
            'SEARCH', 'SORT' => $this->parseNumberList($data),
//            'NAMESPACE' => $data,
//            'ID' => $data,
            'ENABLED' => preg_split('/\s+/', trim($data)),
            default => $data,
        };
    }

    private function parseCapabilities(string $data): array
    {
        return preg_split('/\s+/', trim($data));
    }

    private function parseParenthesizedList(string $data): array
    {
        if (preg_match('/^\((.+)\)$/', trim($data), $matches)) {
            return preg_split('/\s+/', trim($matches[1]));
        }

        return [];
    }

    private function parseListResponse(string $data): array
    {
        $result = ['attributes' => [], 'delimiter' => '', 'name' => ''];

        if (preg_match('/^\(([^)]*)\)\s+NIL\s+"?([^"]*)"?\s*$/', $data, $matches)) {
            $result['attributes'] = $matches[1] !== ''
                ? preg_split('/\s+/', trim($matches[1]))
                : [];
            $result['delimiter'] = '';
            $result['name'] = trim($matches[2], '"');
        } elseif (preg_match('/^\(([^)]*)\)\s+"?([^"]*)"?\s+"?([^"]*)"?\s*$/', $data, $matches)) {
            $result['attributes'] = $matches[1] !== ''
                ? preg_split('/\s+/', trim($matches[1]))
                : [];
            $result['delimiter'] = trim($matches[2], '"');
            $result['name'] = trim($matches[3], '"');
        }

        return $result;
    }

    private function parseStatusResponse(string $data): array
    {
        $result = ['mailbox' => '', 'attributes' => []];

        if (preg_match('/^"?([^"(]+)"?\s*\((.+)\)$/', trim($data), $matches)) {
            $result['mailbox'] = trim($matches[1]);
            $attrs = trim($matches[2]);

            preg_match_all('/(\w+)\s+(\d+)/', $attrs, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $result['attributes'][strtoupper($pair[1])] = (int) $pair[2];
            }
        }

        return $result;
    }

    private function parseNumberList(string $data): array
    {
        $data = trim($data);
        if ($data === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $data);
        $numbers = [];
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $numbers[] = (int) $part;
            }
        }

        return $numbers;
    }

    private function parseFetchData(string $data, int $sequenceNumber): array
    {
        $result = ['seq' => $sequenceNumber];

        $trimmed = ltrim($data);
        if ($trimmed === '' || $trimmed[0] !== '(') {
            return $result;
        }

        // Strip the outer parens. The closing paren is always the last
        // non-whitespace byte for a well-formed FETCH payload.
        $end = strrpos($trimmed, ')');
        if ($end === false || $end < 1) {
            return $result;
        }

        $inner = substr($trimmed, 1, $end - 1);

        $parser = new FetchResponseParser($inner);

        return $result + $parser->parse();
    }

    private function extractResponseData(string $rest, string $prefix): string
    {
        return trim(substr($rest, strlen($prefix)));
    }
}
