<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Mime\HeaderDecoder;
use D4ry\ImapClient\Protocol\Response\FetchResponseParser;
use D4ry\ImapClient\ValueObject\Address;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FetchResponseParser::class)]
#[UsesClass(HeaderDecoder::class)]
#[UsesClass(Address::class)]
#[UsesClass(BodyStructure::class)]
#[UsesClass(Envelope::class)]
#[UsesClass(Uid::class)]
#[UsesClass(FlagSet::class)]
final class FetchResponseParserTest extends TestCase
{
    public function testParsesUidAndSize(): void
    {
        $parsed = (new FetchResponseParser('UID 42 RFC822.SIZE 1234'))->parse();

        self::assertInstanceOf(Uid::class, $parsed['UID']);
        self::assertSame(42, $parsed['UID']->value);
        self::assertSame(1234, $parsed['RFC822.SIZE']);
    }

    public function testParsesFlags(): void
    {
        $parsed = (new FetchResponseParser('FLAGS (\\Seen \\Flagged)'))->parse();

        self::assertInstanceOf(FlagSet::class, $parsed['FLAGS']);
        self::assertTrue($parsed['FLAGS']->has('\\Seen'));
        self::assertTrue($parsed['FLAGS']->has('\\Flagged'));
    }

    public function testParsesInternalDate(): void
    {
        $parsed = (new FetchResponseParser('INTERNALDATE "07-Apr-2026 09:30:15 +0000"'))->parse();

        self::assertSame('07-Apr-2026 09:30:15 +0000', $parsed['INTERNALDATE']);
    }

    public function testParsesEnvelope(): void
    {
        $envelope = '("Mon, 7 Apr 2026 09:30:15 +0000" "Hello" '
            . '(("Alice" NIL "alice" "example.com")) '
            . '(("Alice" NIL "alice" "example.com")) '
            . '(("Alice" NIL "alice" "example.com")) '
            . '(("Bob" NIL "bob" "example.com")) '
            . 'NIL NIL NIL "<id@example.com>")';

        $parsed = (new FetchResponseParser('ENVELOPE ' . $envelope))->parse();

        self::assertInstanceOf(Envelope::class, $parsed['ENVELOPE']);
        self::assertSame('Hello', $parsed['ENVELOPE']->subject);
        self::assertCount(1, $parsed['ENVELOPE']->from);
        self::assertSame('alice@example.com', $parsed['ENVELOPE']->from[0]->email());
        self::assertSame('Alice', $parsed['ENVELOPE']->from[0]->name);
        self::assertSame('Bob', $parsed['ENVELOPE']->to[0]->name);
        self::assertSame('<id@example.com>', $parsed['ENVELOPE']->messageId);
        self::assertSame([], $parsed['ENVELOPE']->cc);
    }

    public function testParsesModSeq(): void
    {
        $parsed = (new FetchResponseParser('MODSEQ (12345)'))->parse();

        self::assertSame(12345, $parsed['MODSEQ']);
    }

    public function testParsesEmptyFlags(): void
    {
        $parsed = (new FetchResponseParser('FLAGS ()'))->parse();

        self::assertInstanceOf(FlagSet::class, $parsed['FLAGS']);
        self::assertSame(0, $parsed['FLAGS']->count());
    }

    public function testParsesEnvelopeWithAllNilFields(): void
    {
        $parsed = (new FetchResponseParser('ENVELOPE ("not a date" NIL NIL NIL NIL NIL NIL NIL NIL NIL)'))->parse();

        $envelope = $parsed['ENVELOPE'];
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertNull($envelope->date);
        self::assertNull($envelope->subject);
        self::assertSame([], $envelope->from);
        self::assertSame([], $envelope->to);
        self::assertSame([], $envelope->cc);
        self::assertSame([], $envelope->bcc);
        self::assertNull($envelope->messageId);
    }

    public function testParsesEnvelopeWithEncodedSubject(): void
    {
        $envelope = '("Mon, 7 Apr 2026 09:30:15 +0000" "=?utf-8?B?SGVsbG8=?=" '
            . '(("Alice" NIL "alice" "example.com")) NIL NIL NIL NIL NIL NIL "<id@example.com>")';

        $parsed = (new FetchResponseParser('ENVELOPE ' . $envelope))->parse();

        self::assertSame('Hello', $parsed['ENVELOPE']->subject);
    }

    public function testParsesBodySection(): void
    {
        $parsed = (new FetchResponseParser('BODY[TEXT] "hello world"'))->parse();

        self::assertArrayHasKey('BODY[TEXT]', $parsed);
        self::assertSame('hello world', $parsed['BODY[TEXT]']);
    }

    public function testParsesBodySectionWithLiteral(): void
    {
        $data = "BODY[HEADER.FIELDS (SUBJECT)] {5}\r\nhello";
        $parsed = (new FetchResponseParser($data))->parse();

        self::assertArrayHasKey('BODY[HEADER.FIELDS (SUBJECT)]', $parsed);
        self::assertSame('hello', $parsed['BODY[HEADER.FIELDS (SUBJECT)]']);
    }

    public function testParsesEmailIdAndThreadId(): void
    {
        $parsed = (new FetchResponseParser('EMAILID (M123) THREADID (T456)'))->parse();

        self::assertSame('M123', $parsed['EMAILID']);
        self::assertSame('T456', $parsed['THREADID']);
    }

    public function testParsesEmailIdNil(): void
    {
        $parsed = (new FetchResponseParser('EMAILID NIL'))->parse();

        self::assertNull($parsed['EMAILID']);
    }

    public function testParsesSaveDate(): void
    {
        $parsed = (new FetchResponseParser('SAVEDATE "01-Jan-2026 00:00:00 +0000"'))->parse();
        self::assertSame('01-Jan-2026 00:00:00 +0000', $parsed['SAVEDATE']);

        $parsedNil = (new FetchResponseParser('SAVEDATE NIL'))->parse();
        self::assertNull($parsedNil['SAVEDATE']);
    }

    public function testParsesSinglePartTextBodyStructure(): void
    {
        $parsed = (new FetchResponseParser(
            'BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 1234 50)'
        ))->parse();

        $bs = $parsed['BODYSTRUCTURE'];
        self::assertInstanceOf(BodyStructure::class, $bs);
        self::assertSame('TEXT', $bs->type);
        self::assertSame('PLAIN', $bs->subtype);
        self::assertSame(['charset' => 'utf-8'], $bs->parameters);
        self::assertSame(ContentTransferEncoding::SevenBit, $bs->encoding);
        self::assertSame(1234, $bs->size);
        self::assertSame('1', $bs->partNumber);
    }

    public function testParsesSinglePartBodyStructureWithDefaultsAndNilEncoding(): void
    {
        // NIL type/subtype default to TEXT/PLAIN, NIL encoding stays null,
        // NIL size becomes 0, NIL parameters list becomes [].
        $parsed = (new FetchResponseParser(
            'BODYSTRUCTURE (NIL NIL NIL NIL NIL NIL NIL)'
        ))->parse();

        $bs = $parsed['BODYSTRUCTURE'];
        self::assertSame('TEXT', $bs->type);
        self::assertSame('PLAIN', $bs->subtype);
        self::assertSame([], $bs->parameters);
        self::assertNull($bs->encoding);
        self::assertSame(0, $bs->size);
    }

    public function testParsesMultipartBodyStructure(): void
    {
        $data = 'BODYSTRUCTURE ('
            . '("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 100 5)'
            . '("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "7BIT" 200 10)'
            . ' "MIXED")';

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('MULTIPART', $bs->type);
        self::assertSame('MIXED', $bs->subtype);
        self::assertCount(2, $bs->parts);
        self::assertSame('1', $bs->parts[0]->partNumber);
        self::assertSame('2', $bs->parts[1]->partNumber);
        self::assertSame('PLAIN', $bs->parts[0]->subtype);
        self::assertSame('HTML', $bs->parts[1]->subtype);
    }

    public function testParsesNestedMultipartBodyStructure(): void
    {
        $data = 'BODYSTRUCTURE ('
            . '("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 1)'
            . '('
                . '("TEXT" "PLAIN" NIL NIL NIL "7BIT" 20 2)'
                . '("TEXT" "HTML" NIL NIL NIL "7BIT" 30 3)'
                . ' "ALTERNATIVE"'
            . ')'
            . ' "MIXED")';

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('MULTIPART', $bs->type);
        self::assertSame('MIXED', $bs->subtype);
        self::assertCount(2, $bs->parts);
        self::assertSame('1', $bs->parts[0]->partNumber);
        self::assertSame('2', $bs->parts[1]->partNumber);

        $nested = $bs->parts[1];
        self::assertSame('MULTIPART', $nested->type);
        self::assertSame('ALTERNATIVE', $nested->subtype);
        self::assertCount(2, $nested->parts);
        self::assertSame('2.1', $nested->parts[0]->partNumber);
        self::assertSame('2.2', $nested->parts[1]->partNumber);
    }

    public function testParsesMultipartBodyStructureWithParametersAndDisposition(): void
    {
        $data = 'BODYSTRUCTURE ('
            . '("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 1)'
            . ' "MIXED" ("BOUNDARY" "abc") ("inline" ("filename" "x.txt")))';

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('MIXED', $bs->subtype);
        self::assertSame(['boundary' => 'abc'], $bs->parameters);
        self::assertSame('inline', $bs->disposition);
        self::assertSame('x.txt', $bs->dispositionFilename);
    }

    public function testParsesSinglePartBodyStructureWithDisposition(): void
    {
        $data = 'BODYSTRUCTURE ("APPLICATION" "OCTET-STREAM" ("NAME" "x.bin") NIL NIL "BASE64" 100 NIL ("attachment" ("filename" "x.bin")))';

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('APPLICATION', $bs->type);
        self::assertSame('OCTET-STREAM', $bs->subtype);
        self::assertSame(ContentTransferEncoding::Base64, $bs->encoding);
        self::assertSame('attachment', $bs->disposition);
        self::assertSame('x.bin', $bs->dispositionFilename);
    }

    public function testParsesBodyStructureMessageRfc822(): void
    {
        // Exercises the MESSAGE/RFC822 branch of skipRemainingFields:
        // envelope + nested body structure + lines.
        $data = 'BODYSTRUCTURE ("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 100 '
            . '("Mon, 7 Apr 2026 00:00:00 +0000" "Sub" NIL NIL NIL NIL NIL NIL NIL NIL) '
            . '("TEXT" "PLAIN" NIL NIL NIL "7BIT" 50 5) 5)';

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('MESSAGE', $bs->type);
        self::assertSame('RFC822', $bs->subtype);
        self::assertSame(100, $bs->size);
    }

    public function testParsesTextBodyStructureWithLines(): void
    {
        // TEXT type uses the TEXT branch of skipRemainingFields, reading the line count.
        $parsed = (new FetchResponseParser(
            'BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "us-ascii") NIL NIL "7BIT" 200 12)'
        ))->parse();

        self::assertSame(200, $parsed['BODYSTRUCTURE']->size);
    }

    public function testReadValueDefaultBranchHandlesAllValueShapes(): void
    {
        // Unknown keys exercise readValue() — quoted, parenthesized list, atom, NIL, literal.
        $data = 'X-FOO "quoted" X-BAR (a b c) X-BAZ NIL X-NUM 42 X-LIT {3}' . "\r\n" . 'ABC';

        $parsed = (new FetchResponseParser($data))->parse();

        self::assertSame('quoted', $parsed['X-FOO']);
        self::assertSame(['a', 'b', 'c'], $parsed['X-BAR']);
        self::assertNull($parsed['X-BAZ']);
        self::assertSame('42', $parsed['X-NUM']);
        self::assertSame('ABC', $parsed['X-LIT']);
    }

    public function testParseStopsOnEmptyKey(): void
    {
        // Trailing whitespace should not produce a phantom entry.
        $parsed = (new FetchResponseParser('UID 1   '))->parse();

        self::assertCount(1, $parsed);
        self::assertSame(1, $parsed['UID']->value);
    }

    public function testParseHandlesEmptyInput(): void
    {
        self::assertSame([], (new FetchResponseParser(''))->parse());
    }

    public function testParsesInternalDateInEnvelopeProducesValidDate(): void
    {
        // Use ISO-style format to avoid PHP's day-of-week-coercion quirks.
        $envelope = '("2026-04-07 09:30:15 +0000" "Hello" NIL NIL NIL NIL NIL NIL NIL NIL)';
        $parsed = (new FetchResponseParser('ENVELOPE ' . $envelope))->parse();

        self::assertNotNull($parsed['ENVELOPE']->date);
        self::assertSame('2026-04-07', $parsed['ENVELOPE']->date->format('Y-m-d'));
    }

    public function testParseBreaksOnImmediateBreakerCharacter(): void
    {
        // Leading '(' makes readAtom return '' immediately so the parse loop breaks.
        self::assertSame([], (new FetchResponseParser('(noise)'))->parse());
    }

    public function testReadValueReturnsNullAtEndOfInput(): void
    {
        // Unknown key with no value: readValue is called with no remaining bytes
        // and must return null without error.
        $parsed = (new FetchResponseParser('X-FOO'))->parse();
        self::assertNull($parsed['X-FOO']);
    }

    public function testReadQuotedHandlesEscapedQuoteAndBackslash(): void
    {
        // INTERNALDATE goes through readQuoted; embed escape sequences inside.
        $parsed = (new FetchResponseParser('INTERNALDATE "a\\"b\\\\c"'))->parse();
        self::assertSame('a"b\\c', $parsed['INTERNALDATE']);
    }

    public function testParsesSinglePartBodyStructureWithNilDispositionField(): void
    {
        // After the standard fields and MD5, an explicit NIL disposition exercises
        // the NIL branch of tryReadDisposition.
        $data = 'BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 5 "md5sum" NIL)';
        $parsed = (new FetchResponseParser($data))->parse();

        self::assertNull($parsed['BODYSTRUCTURE']->disposition);
        self::assertNull($parsed['BODYSTRUCTURE']->dispositionFilename);
    }

    public function testParsesSinglePartBodyStructureWithStringInDispositionSlot(): void
    {
        // A non-paren, non-NIL value in the disposition slot returns null
        // (peek() !== '(' branch of tryReadDisposition).
        $data = 'BODYSTRUCTURE ("TEXT" "PLAIN" NIL NIL NIL "7BIT" 10 5 "md5sum" "language")';
        $parsed = (new FetchResponseParser($data))->parse();

        self::assertNull($parsed['BODYSTRUCTURE']->disposition);
    }

    public function testParsesEnvelopeWithLiteralSubject(): void
    {
        // Subject delivered as a literal exercises readNString's literal branch.
        $envelope = "(NIL {5}\r\nHello NIL NIL NIL NIL NIL NIL NIL NIL)";
        $parsed = (new FetchResponseParser('ENVELOPE ' . $envelope))->parse();

        self::assertSame('Hello', $parsed['ENVELOPE']->subject);
    }

    public function testReadQuotedHandlesUnterminatedQuoteGracefully(): void
    {
        // No closing quote: readQuoted exits the loop and returns the accumulated buffer.
        $parsed = (new FetchResponseParser('INTERNALDATE "no end here'))->parse();
        self::assertSame('no end here', $parsed['INTERNALDATE']);
    }

    public function testParsesBodyStructureWithDeeplyNestedTrailingExtensionAndEscapes(): void
    {
        // Deeply nested parens after disposition exercise the depth++ branch
        // of skipNestedParens; an escaped quote inside exercises the \\ branch.
        $data = "BODYSTRUCTURE (\"TEXT\" \"PLAIN\" NIL NIL NIL \"7BIT\" 10 5 NIL NIL "
            . "(outer (inner \"a\\\"b\")))";

        $parsed = (new FetchResponseParser($data))->parse();

        self::assertSame('TEXT', $parsed['BODYSTRUCTURE']->type);
    }

    public function testParsesBodyStructureWithExtensionTrailingFields(): void
    {
        // Extra trailing extension fields after the disposition exercise
        // skipToCloseParen branches: nested paren list, quoted string, literal,
        // and bare atom characters.
        $data = "BODYSTRUCTURE (\"TEXT\" \"PLAIN\" NIL NIL NIL \"7BIT\" 10 5 NIL "
            . "(\"inline\" (\"filename\" \"x\")) \"en-US\" \"loc\" "
            . "(extra nested) {3}\r\nABC trailingatom)";

        $parsed = (new FetchResponseParser($data))->parse();
        $bs = $parsed['BODYSTRUCTURE'];

        self::assertSame('inline', $bs->disposition);
        self::assertSame('x', $bs->dispositionFilename);
    }

    public function testParsesAddressListWithMultipleAddresses(): void
    {
        $envelope = '(NIL NIL '
            . '(("Alice" NIL "alice" "a.com")("Bob" NIL "bob" "b.com")) '
            . 'NIL NIL NIL NIL NIL NIL NIL)';
        $parsed = (new FetchResponseParser('ENVELOPE ' . $envelope))->parse();

        self::assertCount(2, $parsed['ENVELOPE']->from);
        self::assertSame('alice@a.com', $parsed['ENVELOPE']->from[0]->email());
        self::assertSame('bob@b.com', $parsed['ENVELOPE']->from[1]->email());
    }

    public function testParsesEmailIdAtEndOfInputReturnsNull(): void
    {
        // 'EMAILID ' (trailing space) leaves the parser at EOF inside
        // readParenthesizedSingle: isNil() takes its insufficient-bytes
        // early-return, then readNString() takes its EOF early-return.
        $parsed = (new FetchResponseParser('EMAILID '))->parse();

        self::assertArrayHasKey('EMAILID', $parsed);
        self::assertNull($parsed['EMAILID']);
    }

    public function testParsesEmailIdWithSingleCharValue(): void
    {
        $parsed = (new FetchResponseParser('EMAILID X'))->parse();

        self::assertSame('X', $parsed['EMAILID']);
    }
}
