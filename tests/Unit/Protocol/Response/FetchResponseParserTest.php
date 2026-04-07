<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Mime\HeaderDecoder;
use D4ry\ImapClient\Protocol\Response\FetchResponseParser;
use D4ry\ImapClient\ValueObject\Address;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FetchResponseParser::class)]
#[UsesClass(HeaderDecoder::class)]
#[UsesClass(Address::class)]
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
}
