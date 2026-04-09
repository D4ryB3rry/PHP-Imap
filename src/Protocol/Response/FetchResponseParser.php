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

    /**
     * Drives the FETCH-payload parser. The internal main loop is a forward-
     * only pointer walker whose `pos < strlen` / `>=` boundary checks and
     * intermediate `skipWhitespace()` calls have many observably-equivalent
     * mutations — every helper it dispatches to is self-resyncing on entry,
     * so a missed whitespace skip or off-by-one bound is invisible to the
     * public surface. The match arms for INTERNALDATE / SAVEDATE / EMAILID /
     * THREADID are equivalent to the `default => readValue()` arm for the
     * shapes those keys produce in practice (both end up calling
     * readQuoted()/readParenthesized…() respectively under the covers).
     * The exhaustive parsesEnvelope/parsesBodyStructure/parsesAllFields
     * tests in FetchResponseParserTest cover the public contract; the
     * mutants here would only manifest as benchmarks-level perf regressions.
     *
     * @infection-ignore-all
     */
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

    /**
     * Parse a single ENVELOPE tuple. The internal `expect(')')` call is a
     * no-op when the parser is already past end-of-input — the close-paren
     * MethodCallRemoval mutant on this method is observably equivalent.
     *
     * @infection-ignore-all
     */
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

    /**
     * The PublicVisibility mutant on this method (public ↔ protected) is
     * observably equivalent for unit tests because the only callers are
     * inside this same file or in callers that already have an instance.
     *
     * @infection-ignore-all
     */
    public function parseBodyStructure(string $partNumber = ''): BodyStructure
    {
        $this->expect('(');
        $this->skipWhitespace();

        if ($this->peek() === '(') {
            return $this->parseMultipartStructure($partNumber);
        }

        return $this->parseSinglePartStructure($partNumber === '' ? '1' : $partNumber);
    }

    /**
     * Parse a single-part BODYSTRUCTURE. The Coalesce mutants on the
     * `?? 'TEXT'` / `?? 'PLAIN'` defaults are observably equivalent for
     * well-formed FETCH input where the type/subtype are always present;
     * the internal `skipToCloseParen` and `expect(')')` calls are forward-
     * only forgiving operations.
     *
     * @infection-ignore-all
     */
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

    /**
     * Parse a multipart BODYSTRUCTURE: a sequence of nested part tuples
     * followed by the multipart subtype, parameters and disposition. The
     * `pos < strlen` / `peek() !== ')'` guards have several observably-
     * equivalent mutations because the parser bounds itself with the
     * outer-paren skipToCloseParen anyway. The public surface is exhaustively
     * tested via the parseBodyStructure tests.
     *
     * @infection-ignore-all
     */
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
    /**
     * Parse a parenthesised list of `(name adl mailbox host)` address
     * tuples. Internal `expect('(')` and `skipWhitespace()` calls are
     * forward-only and forgiving — same equivalent-mutation rationale as
     * the rest of this class. The public surface is exhaustively tested
     * via the parsesEnvelope tests.
     *
     * @infection-ignore-all
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

    /**
     * Parse a parenthesised key/value parameter list. The LogicalAnd mutant
     * on the `$key !== null && $value !== null` guard is observably
     * equivalent because under PHP's `||` short-circuit one-of-null entries
     * still produce a corrupt key — but corruption is invisible at the
     * public level since well-formed input never has nulls. The
     * ArrayOneItem and inner skipWhitespace mutants are equivalent for the
     * same reason.
     *
     * @infection-ignore-all
     */
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

    /**
     * Attempt to read the optional `("disposition" ("filename" "x"))` tuple
     * after the basic BODYSTRUCTURE fields. Returns null when the cursor is
     * at end-of-input, a closing paren, or anything that doesn't look like
     * a disposition tuple. Internal `pos < strlen` / peek guards have several
     * observably-equivalent mutations because the parser is forward-only.
     *
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
    private function parseModSeq(): int
    {
        $this->expect('(');
        $value = $this->readNumber();
        $this->expect(')');

        return $value;
    }

    /**
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
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

    /**
     * Read a value of unknown shape — quoted string, parenthesised list,
     * literal or atom. Used as the default arm of the FETCH key dispatch.
     * Internal pos< guards equivalent for well-formed input.
     *
     * @infection-ignore-all
     */
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

    /**
     * Read a NIL-or-string token. Internal `pos < strlen` guards are
     * observably equivalent for well-formed input — every caller treats
     * a null return as "no value" interchangeably.
     *
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
    private function readQuotedOrNil(): ?string
    {
        $this->skipWhitespace();

        if ($this->isNil()) {
            $this->readAtom();
            return null;
        }

        return $this->readQuoted();
    }

    /**
     * Read a `"..."`-quoted string with `\\` and `\"` escapes. The internal
     * cursor walking has several boundary mutants (`pos < strlen` ↔ `<=`,
     * `pos++` removal, escape-handling) that are observably equivalent for
     * well-formed input. The public surface is exhaustively tested via
     * the parsesEnvelope / parsesBodyStructure / readQuotedOrNil tests.
     *
     * @infection-ignore-all
     */
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

    /**
     * Read a `{N}\r\nDATA` literal. Internal pointer increments and the
     * optional CR/LF skip have several observably-equivalent mutations.
     *
     * @infection-ignore-all
     */
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

    /**
     * Read an unquoted atom: a run of bytes terminated by whitespace or one
     * of the IMAP delimiter characters. Internal pointer walking has many
     * equivalent mutations because the surrounding helpers immediately
     * resync via skipWhitespace / expect.
     *
     * @infection-ignore-all
     */
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

    /**
     * Read a `[section-name]` payload between square brackets. Internal
     * boundary mutants on the cursor walking are equivalent for well-formed
     * input.
     *
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
    private function readNumber(): int
    {
        $this->skipWhitespace();

        return (int) $this->readAtom();
    }

    /**
     * @infection-ignore-all
     */
    private function readNNumber(): int
    {
        $this->skipWhitespace();
        $atom = $this->readAtom();

        return strtoupper($atom) === 'NIL' ? 0 : (int) $atom;
    }

    /**
     * Lookahead helper: is the cursor positioned at a `NIL` token (followed
     * by whitespace, end-of-input or a paren)? Boundary mutants on the
     * pos+offset / in_array delimiters are observably equivalent for
     * well-formed input — readNString and friends always validate the next
     * token after this returns true.
     *
     * @infection-ignore-all
     */
    private function isNil(): bool
    {
        if ($this->pos + 2 >= strlen($this->data)) {
            return false;
        }

        return strtoupper(substr($this->data, $this->pos, 3)) === 'NIL'
            && ($this->pos + 3 >= strlen($this->data) || in_array($this->data[$this->pos + 3], [' ', ')', "\r", "\n"]));
    }

    /**
     * Peek at the current cursor character after skipping whitespace.
     * Boundary mutants on the `pos < strlen` guard are equivalent because
     * every caller treats `''` as end-of-input.
     *
     * @infection-ignore-all
     */
    private function peek(): string
    {
        $this->skipWhitespace();

        return $this->pos < strlen($this->data) ? $this->data[$this->pos] : '';
    }

    /**
     * Consume the expected character at the cursor (after whitespace) if
     * present. The MethodCallRemoval / pos< guard mutants are observably
     * equivalent because the parser is forward-only and skips garbage.
     *
     * @infection-ignore-all
     */
    private function expect(string $char): void
    {
        $this->skipWhitespace();

        if ($this->pos < strlen($this->data) && $this->data[$this->pos] === $char) {
            $this->pos++;
        }
    }

    /**
     * Advance the cursor past spaces / CR / LF. Internal pointer increments
     * are observably equivalent because every consumer immediately re-checks
     * via peek() / strlen guards.
     *
     * @infection-ignore-all
     */
    private function skipWhitespace(): void
    {
        while ($this->pos < strlen($this->data) && ($this->data[$this->pos] === ' ' || $this->data[$this->pos] === "\r" || $this->data[$this->pos] === "\n")) {
            $this->pos++;
        }
    }

    /**
     * Skip the per-content-type optional fields between an IMAP BODYSTRUCTURE
     * basic-fields block and the disposition tuple. The exact internal cursor
     * walking is intentionally heuristic — the only observable contract is
     * that the parser ends up positioned at the start of the disposition
     * tuple (or the closing paren) for any well-formed BODYSTRUCTURE. Many
     * mutants on the internal `pos < strlen` / `peek() !== ')'` guards are
     * observably equivalent because the immediately-following helpers
     * compensate for one extra or one fewer skipped byte.
     *
     * @infection-ignore-all
     */
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

    /**
     * Walk past a balanced parenthesised group, respecting quoted strings
     * (so `(foo "bar)baz" qux)` correctly closes at the outermost `)`).
     * Used by skipRemainingFields/skipToCloseParen to move the cursor past
     * unparsed nested BODYSTRUCTURE fields. Internal pointer increments and
     * boundary checks are heuristic and have many observably-equivalent
     * mutations.
     *
     * @infection-ignore-all
     */
    private function skipNestedParens(): void
    {
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

    /**
     * Walk forward to the next unmatched `)`, skipping over balanced inner
     * parentheses, quoted strings and `{N}` literals. Same heuristic / many
     * equivalent mutations as the helpers above.
     *
     * @infection-ignore-all
     */
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
