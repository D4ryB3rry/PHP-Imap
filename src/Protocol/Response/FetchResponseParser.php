<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Mime\HeaderDecoder;
use D4ry\ImapClient\ValueObject\Address;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\Uid;

class FetchResponseParser
{
    private string $data;
    private int $pos = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function parse(): array
    {
        $result = [];

        while ($this->pos < strlen($this->data)) {
            $this->skipWhitespace();

            if ($this->pos >= strlen($this->data)) {
                break;
            }

            $key = $this->readAtom();

            if ($key === '') {
                break;
            }

            $upperKey = strtoupper($key);
            $this->skipWhitespace();

            if ($upperKey === 'BODY' && $this->peek() === '[') {
                $section = $this->readSection();
                $upperKey = 'BODY[' . $section . ']';
                $this->skipWhitespace();
            }

            $result[$upperKey] = match ($upperKey) {
                'FLAGS' => $this->parseFlags(),
                'ENVELOPE' => $this->parseEnvelope(),
                'BODYSTRUCTURE' => $this->parseBodyStructure(),
                'UID' => new Uid($this->readNumber()),
                'RFC822.SIZE' => $this->readNumber(),
                'INTERNALDATE' => $this->readQuoted(),
                'MODSEQ' => $this->parseModSeq(),
                'EMAILID' => $this->readParenthesizedSingle(),
                'THREADID' => $this->readParenthesizedSingle(),
                'SAVEDATE' => $this->readQuotedOrNil(),
                default => $this->readValue(),
            };
        }

        return $result;
    }

    private function parseFlags(): FlagSet
    {
        $flags = $this->readParenthesizedList();

        return new FlagSet($flags);
    }

    private function parseEnvelope(): Envelope
    {
        $this->expect('(');

        $date = $this->readNString();
        $rawSubject = $this->readNString();
        $subject = $rawSubject !== null ? HeaderDecoder::decode($rawSubject) : null;
        $from = $this->readAddressList();
        $sender = $this->readAddressList();
        $replyTo = $this->readAddressList();
        $to = $this->readAddressList();
        $cc = $this->readAddressList();
        $bcc = $this->readAddressList();
        $inReplyTo = $this->readNString();
        $messageId = $this->readNString();

        $this->expect(')');

        $parsedDate = null;
        if ($date !== null) {
            try {
                $parsedDate = new \DateTimeImmutable($date);
            } catch (\Exception) {
                $parsedDate = null;
            }
        }

        return new Envelope(
            date: $parsedDate,
            subject: $subject,
            from: $from,
            sender: $sender,
            replyTo: $replyTo,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            inReplyTo: $inReplyTo,
            messageId: $messageId,
        );
    }

    public function parseBodyStructure(string $partNumber = ''): BodyStructure
    {
        $this->expect('(');
        $this->skipWhitespace();

        if ($this->peek() === '(') {
            return $this->parseMultipartStructure($partNumber);
        }

        return $this->parseSinglePartStructure($partNumber === '' ? '1' : $partNumber);
    }

    private function parseSinglePartStructure(string $partNumber): BodyStructure
    {
        $type = $this->readNString() ?? 'TEXT';
        $subtype = $this->readNString() ?? 'PLAIN';
        $parameters = $this->readParameterList();
        $id = $this->readNString();
        $description = $this->readNString();
        $encodingStr = $this->readNString();
        $size = $this->readNNumber();

        $encoding = null;
        if ($encodingStr !== null) {
            $encoding = ContentTransferEncoding::tryFrom(strtolower($encodingStr));
        }

        $disposition = null;
        $dispositionFilename = null;

        $this->skipRemainingFields($type, $subtype);

        $dispData = $this->tryReadDisposition();
        if ($dispData !== null) {
            $disposition = $dispData['disposition'];
            $dispositionFilename = $dispData['filename'];
        }

        $this->skipToCloseParen();
        $this->expect(')');

        return new BodyStructure(
            type: $type,
            subtype: $subtype,
            parameters: $parameters,
            id: $id,
            description: $description,
            encoding: $encoding,
            size: $size,
            parts: [],
            disposition: $disposition,
            dispositionFilename: $dispositionFilename,
            partNumber: $partNumber,
        );
    }

    private function parseMultipartStructure(string $partNumber): BodyStructure
    {
        $parts = [];
        $partIndex = 1;

        while ($this->peek() === '(') {
            $childPartNumber = $partNumber === '' ? (string) $partIndex : $partNumber . '.' . $partIndex;
            $parts[] = $this->parseBodyStructure($childPartNumber);
            $this->skipWhitespace();
            $partIndex++;
        }

        $subtype = $this->readNString() ?? 'MIXED';

        $disposition = null;
        $dispositionFilename = null;
        $parameters = [];

        $this->skipWhitespace();
        if ($this->pos < strlen($this->data) && $this->peek() !== ')') {
            $parameters = $this->readParameterList();
        }

        $this->skipWhitespace();
        if ($this->pos < strlen($this->data) && $this->peek() === '(') {
            $dispData = $this->tryReadDisposition();
            if ($dispData !== null) {
                $disposition = $dispData['disposition'];
                $dispositionFilename = $dispData['filename'];
            }
        }

        $this->skipToCloseParen();
        $this->expect(')');

        return new BodyStructure(
            type: 'MULTIPART',
            subtype: $subtype,
            parameters: $parameters,
            id: null,
            description: null,
            encoding: null,
            size: 0,
            parts: $parts,
            disposition: $disposition,
            dispositionFilename: $dispositionFilename,
            partNumber: $partNumber,
        );
    }

    /**
     * @return Address[]
     */
    private function readAddressList(): array
    {
        $this->skipWhitespace();

        if ($this->isNil()) {
            $this->readAtom();
            return [];
        }

        $this->expect('(');
        $addresses = [];

        while ($this->peek() !== ')') {
            $this->expect('(');
            $rawName = $this->readNString();
            $this->readNString(); // adl (source route) — obsolete
            $mailbox = $this->readNString() ?? '';
            $host = $this->readNString() ?? '';
            $this->expect(')');

            $name = $rawName !== null ? HeaderDecoder::decode($rawName) : null;
            $addresses[] = new Address($name, $mailbox, $host);
            $this->skipWhitespace();
        }

        $this->expect(')');

        return $addresses;
    }

    private function readParameterList(): array
    {
        $this->skipWhitespace();

        if ($this->isNil()) {
            $this->readAtom();
            return [];
        }

        $this->expect('(');
        $params = [];

        while ($this->peek() !== ')') {
            $key = $this->readNString();
            $value = $this->readNString();
            if ($key !== null && $value !== null) {
                $params[strtolower($key)] = $value;
            }
            $this->skipWhitespace();
        }

        $this->expect(')');

        return $params;
    }

    private function tryReadDisposition(): ?array
    {
        $this->skipWhitespace();

        if ($this->pos >= strlen($this->data) || $this->peek() === ')') {
            return null;
        }

        if ($this->isNil()) {
            $this->readAtom();
            return null;
        }

        if ($this->peek() !== '(') {
            return null;
        }

        $this->expect('(');
        $disposition = $this->readNString();
        $params = $this->readParameterList();
        $this->expect(')');

        return [
            'disposition' => $disposition,
            'filename' => $params['filename'] ?? null,
        ];
    }

    private function parseModSeq(): int
    {
        $this->expect('(');
        $value = $this->readNumber();
        $this->expect(')');

        return $value;
    }

    private function readParenthesizedSingle(): ?string
    {
        $this->skipWhitespace();
        if ($this->isNil()) {
            $this->readAtom();
            return null;
        }

        $this->expect('(');
        $value = $this->readNString();
        $this->expect(')');

        return $value;
    }

    private function readParenthesizedList(): array
    {
        $this->expect('(');
        $items = [];

        while ($this->peek() !== ')') {
            $items[] = $this->readAtom();
            $this->skipWhitespace();
        }

        $this->expect(')');

        return $items;
    }

    private function readValue(): mixed
    {
        $this->skipWhitespace();

        if ($this->pos >= strlen($this->data)) {
            return null;
        }

        if ($this->peek() === '"') {
            return $this->readQuoted();
        }

        if ($this->peek() === '(') {
            return $this->readParenthesizedList();
        }

        if ($this->peek() === '{') {
            return $this->readLiteral();
        }

        $atom = $this->readAtom();

        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    private function readNString(): ?string
    {
        $this->skipWhitespace();

        if ($this->pos >= strlen($this->data)) {
            return null;
        }

        if ($this->peek() === '"') {
            return $this->readQuoted();
        }

        if ($this->peek() === '{') {
            return $this->readLiteral();
        }

        $atom = $this->readAtom();

        return strtoupper($atom) === 'NIL' ? null : $atom;
    }

    private function readQuotedOrNil(): ?string
    {
        $this->skipWhitespace();

        if ($this->isNil()) {
            $this->readAtom();
            return null;
        }

        return $this->readQuoted();
    }

    private function readQuoted(): string
    {
        $this->expect('"');
        $result = '';

        while ($this->pos < strlen($this->data)) {
            $char = $this->data[$this->pos];

            if ($char === '\\') {
                $this->pos++;
                if ($this->pos < strlen($this->data)) {
                    $result .= $this->data[$this->pos];
                    $this->pos++;
                }
                continue;
            }

            if ($char === '"') {
                $this->pos++;
                return $result;
            }

            $result .= $char;
            $this->pos++;
        }

        return $result;
    }

    private function readLiteral(): string
    {
        $this->expect('{');
        $sizeStr = '';

        while ($this->pos < strlen($this->data) && $this->data[$this->pos] !== '}') {
            $sizeStr .= $this->data[$this->pos];
            $this->pos++;
        }

        $this->expect('}');
        $size = (int) rtrim($sizeStr, '+');

        if ($this->pos < strlen($this->data) && $this->data[$this->pos] === "\r") {
            $this->pos++;
        }
        if ($this->pos < strlen($this->data) && $this->data[$this->pos] === "\n") {
            $this->pos++;
        }

        $data = substr($this->data, $this->pos, $size);
        $this->pos += $size;

        return $data;
    }

    private function readAtom(): string
    {
        $this->skipWhitespace();
        $atom = '';

        while ($this->pos < strlen($this->data)) {
            $char = $this->data[$this->pos];

            if ($char === ' ' || $char === '(' || $char === ')' || $char === '"' || $char === '{' || $char === '[' || $char === ']') {
                break;
            }

            $atom .= $char;
            $this->pos++;
        }

        return $atom;
    }

    private function readSection(): string
    {
        $this->expect('[');
        $section = '';

        while ($this->pos < strlen($this->data) && $this->data[$this->pos] !== ']') {
            $section .= $this->data[$this->pos];
            $this->pos++;
        }

        $this->expect(']');

        return $section;
    }

    private function readNumber(): int
    {
        $this->skipWhitespace();

        return (int) $this->readAtom();
    }

    private function readNNumber(): int
    {
        $this->skipWhitespace();
        $atom = $this->readAtom();

        return strtoupper($atom) === 'NIL' ? 0 : (int) $atom;
    }

    private function isNil(): bool
    {
        if ($this->pos + 2 >= strlen($this->data)) {
            return false;
        }

        return strtoupper(substr($this->data, $this->pos, 3)) === 'NIL'
            && ($this->pos + 3 >= strlen($this->data) || in_array($this->data[$this->pos + 3], [' ', ')', "\r", "\n"]));
    }

    private function peek(): string
    {
        $this->skipWhitespace();

        return $this->pos < strlen($this->data) ? $this->data[$this->pos] : '';
    }

    private function expect(string $char): void
    {
        $this->skipWhitespace();

        if ($this->pos < strlen($this->data) && $this->data[$this->pos] === $char) {
            $this->pos++;
        }
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < strlen($this->data) && ($this->data[$this->pos] === ' ' || $this->data[$this->pos] === "\r" || $this->data[$this->pos] === "\n")) {
            $this->pos++;
        }
    }

    private function skipRemainingFields(string $type, string $subtype): void
    {
        if (strtolower($type) === 'text') {
            $this->skipWhitespace();
            if ($this->pos < strlen($this->data) && $this->peek() !== ')' && $this->peek() !== '(') {
                $this->readNNumber(); // lines
            }
        } elseif (strtolower($type) === 'message' && strtolower($subtype) === 'rfc822') {
            // envelope, body structure, lines
            $this->skipWhitespace();
            if ($this->peek() === '(') {
                $this->skipNestedParens();
                $this->skipWhitespace();
                if ($this->peek() === '(') {
                    $this->skipNestedParens();
                }
                $this->skipWhitespace();
                if ($this->peek() !== ')' && $this->peek() !== '(') {
                    $this->readNNumber();
                }
            }
        }

        // Skip MD5 field if present
        $this->skipWhitespace();
        if ($this->pos < strlen($this->data) && $this->peek() !== ')' && $this->peek() !== '(') {
            $this->readNString();
        }
    }

    private function skipNestedParens(): void
    {
        if ($this->peek() !== '(') {
            return;
        }

        $this->pos++;
        $depth = 1;

        while ($this->pos < strlen($this->data) && $depth > 0) {
            $char = $this->data[$this->pos];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '"') {
                $this->pos++;
                while ($this->pos < strlen($this->data) && $this->data[$this->pos] !== '"') {
                    if ($this->data[$this->pos] === '\\') {
                        $this->pos++;
                    }
                    $this->pos++;
                }
            }
            $this->pos++;
        }
    }

    private function skipToCloseParen(): void
    {
        $this->skipWhitespace();

        while ($this->pos < strlen($this->data) && $this->data[$this->pos] !== ')') {
            if ($this->data[$this->pos] === '(') {
                $this->skipNestedParens();
            } elseif ($this->data[$this->pos] === '"') {
                $this->readQuoted();
            } elseif ($this->data[$this->pos] === '{') {
                $this->readLiteral();
            } else {
                $this->pos++;
            }
        }
    }
}
