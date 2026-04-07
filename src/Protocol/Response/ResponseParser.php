<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Exception\ProtocolException;

class ResponseParser
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
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
            $literalData = $this->connection->readBytes($literalSize);

            // Preserve {N}\r\n<data> format so FetchResponseParser::readLiteral() can handle it
            $parts[] = $line;
            $parts[] = $literalData;

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
            'NAMESPACE' => $data,
            'ID' => $data,
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

        if (preg_match('/^\(([^)]*)\)\s+"?([^"]*)"?\s+"?([^"]*)"?\s*$/', $data, $matches)) {
            $result['attributes'] = $matches[1] !== ''
                ? preg_split('/\s+/', trim($matches[1]))
                : [];
            $result['delimiter'] = trim($matches[2], '"');
            $result['name'] = trim($matches[3], '"');
        } elseif (preg_match('/^\(([^)]*)\)\s+NIL\s+"?([^"]*)"?\s*$/', $data, $matches)) {
            $result['attributes'] = $matches[1] !== ''
                ? preg_split('/\s+/', trim($matches[1]))
                : [];
            $result['delimiter'] = '';
            $result['name'] = trim($matches[2], '"');
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

        if (!preg_match('/^\((.+)\)$/s', trim($data), $matches)) {
            return $result;
        }

        $parser = new FetchResponseParser($matches[1]);
        $result = array_merge($result, $parser->parse());

        return $result;
    }

    private function extractResponseData(string $rest, string $prefix): string
    {
        return trim(substr($rest, strlen($prefix)));
    }
}
