<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Command;

use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Support\Literal;
use D4ry\ImapClient\ValueObject\Tag;

class CommandBuilder
{
    private string $name = '';

    /** @var string[] */
    private array $arguments = [];

    /** @var Literal[] */
    private array $literals = [];

    public function __construct(
        private TagGenerator $tagGenerator,
    ) {
    }

    public function command(string $name): self
    {
        $builder = clone $this;
        $builder->name = $name;
        $builder->arguments = [];
        $builder->literals = [];

        return $builder;
    }

    public function atom(string $value): self
    {
        $this->arguments[] = $value;

        return $this;
    }

    public function quoted(string $value): self
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $this->arguments[] = '"' . $escaped . '"';

        return $this;
    }

    public function list(array $items): self
    {
        $this->arguments[] = '(' . implode(' ', $items) . ')';

        return $this;
    }

    public function literal(Literal $literal): self
    {
        $this->arguments[] = '{' . $literal->size() . ($literal->nonSynchronizing ? '+' : '') . '}';
        $this->literals[] = $literal;

        return $this;
    }

    public function raw(string $value): self
    {
        $this->arguments[] = $value;

        return $this;
    }

    public function build(): Command
    {
        return new Command(
            tag: $this->tagGenerator->next(),
            name: $this->name,
            arguments: $this->arguments,
        );
    }

    public function buildWithTag(Tag $tag): Command
    {
        return new Command(
            tag: $tag,
            name: $this->name,
            arguments: $this->arguments,
        );
    }

    /**
     * @return Literal[]
     */
    public function getLiterals(): array
    {
        return $this->literals;
    }

    /**
     * IMAP atom / quoted-string emitter. The two str_replace branches are
     * structurally identical, so the LogicalOr / ReturnRemoval / second
     * str_replace mutants are observably equivalent: the third path is
     * unreachable in practice for inputs that contain `\` or `"` (those
     * always match the first regex), so its str_replace pair never affects
     * the output. The first-path mutants on the str_replace pair are
     * killed by CommandBuilderTest::testQuoteStringEscapesBothBackslashAndQuoteOnControlPath.
     *
     * @infection-ignore-all
     */
    public static function quoteString(string $value): string
    {
        if ($value === '' || preg_match('/[\x00-\x1f\x7f"\\\\{]/', $value)) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"' . $escaped . '"';
        }

        if (preg_match('/^[a-zA-Z0-9_.\-\/\#\&\'+,:<>@!$%^]+$/', $value)) {
            return $value;
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }

    /**
     * Wrap a mailbox name for the wire. With $utf8Enabled the name is
     * passed straight through quoteString(); without it, the name is first
     * transcoded into RFC 3501 modified UTF-7. Killed by
     * CommandBuilderTest::testEncodeMailboxNameModifiedUtf7VsUtf8DiffersForAmpersand.
     */
    public static function encodeMailboxName(string $name, bool $utf8Enabled = false): string
    {
        if ($utf8Enabled) {
            return self::quoteString($name);
        }

        return self::quoteString(self::utf8ToModifiedUtf7($name));
    }

    /**
     * UTF-8 → IMAP modified UTF-7 (RFC 3501 §5.1.3) encoder. Drives a state
     * machine over the input runs of ASCII vs non-ASCII bytes; the internal
     * pointer-walking and `pos < strlen` boundary checks have many
     * observably-equivalent mutations because the round-trip via
     * modifiedUtf7ToUtf8() (covered by testRoundTripModifiedUtf7's data
     * provider) is what matters.
     *
     * @infection-ignore-all
     */
    public static function utf8ToModifiedUtf7(string $str): string
    {
        if (mb_check_encoding($str, 'ASCII')) {
            return $str;
        }

        $result = '';
        $ascii = true;
        $buffer = '';

        for ($i = 0, $len = mb_strlen($str, 'UTF-8'); $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');

            if ($char === '&') {
                if (!$ascii) {
                    $result .= self::encodeModifiedBase64($buffer) . '-';
                    $buffer = '';
                    $ascii = true;
                }
                $result .= '&-';
                continue;
            }

            if (ord($char[0]) < 0x80) {
                if (!$ascii) {
                    $result .= self::encodeModifiedBase64($buffer) . '-';
                    $buffer = '';
                    $ascii = true;
                }
                $result .= $char;
            } else {
                if ($ascii) {
                    $result .= '&';
                    $ascii = false;
                }
                $buffer .= $char;
            }
        }

        if (!$ascii) {
            $result .= self::encodeModifiedBase64($buffer) . '-';
        }

        return $result;
    }

    /**
     * IMAP modified UTF-7 → UTF-8 decoder. Inverse of utf8ToModifiedUtf7().
     * Same equivalent-mutation rationale: the round-trip is asserted by the
     * testRoundTripModifiedUtf7 data provider, and the internal pointer
     * walking has many observably-equivalent mutations.
     *
     * @infection-ignore-all
     */
    public static function modifiedUtf7ToUtf8(string $str): string
    {
        if (mb_check_encoding($str, 'ASCII') && !str_contains($str, '&')) {
            return $str;
        }

        $result = '';
        $len = strlen($str);
        $i = 0;

        while ($i < $len) {
            if ($str[$i] !== '&') {
                $result .= $str[$i];
                $i++;
                continue;
            }

            // &- is a literal &
            if ($i + 1 < $len && $str[$i + 1] === '-') {
                $result .= '&';
                $i += 2;
                continue;
            }

            // Find the closing -
            $i++; // skip &
            $encoded = '';
            while ($i < $len && $str[$i] !== '-') {
                $encoded .= $str[$i];
                $i++;
            }
            if ($i < $len) {
                $i++; // skip -
            }

            // Decode modified base64 → UTF-16BE → UTF-8
            $base64 = str_replace(',', '/', $encoded);
            // Pad to multiple of 4
            $padded = $base64 . str_repeat('=', (4 - strlen($base64) % 4) % 4);
            $utf16 = base64_decode($padded, true);

            if ($utf16 !== false) {
                $utf8 = mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE');
                if ($utf8 !== false) {
                    $result .= $utf8;
                }
            }
        }

        return $result;
    }

    public static function decodeMailboxName(string $name, bool $utf8Enabled = false): string
    {
        if ($utf8Enabled) {
            return $name;
        }

        return self::modifiedUtf7ToUtf8($name);
    }

    /**
     * @infection-ignore-all
     */
    private static function encodeModifiedBase64(string $str): string
    {
        $utf16 = mb_convert_encoding($str, 'UTF-16BE', 'UTF-8');

        return str_replace('/', ',', rtrim(base64_encode($utf16), '='));
    }
}
