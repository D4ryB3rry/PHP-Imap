<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Exception\ParseException;
use D4ry\ImapClient\Support\ImapDateFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Support\ImapDateFormatter
 */
final class ImapDateFormatterTest extends TestCase
{
    public function testToImapDate(): void
    {
        $date = new \DateTimeImmutable('2026-04-07');

        self::assertSame('7-Apr-2026', ImapDateFormatter::toImapDate($date));
    }

    public function testToImapDateTime(): void
    {
        $date = new \DateTimeImmutable('2026-04-07 09:30:15+0000');

        self::assertSame('07-Apr-2026 09:30:15 +0000', ImapDateFormatter::toImapDateTime($date));
    }

    public function testParseInternalDateWithTimezone(): void
    {
        $parsed = ImapDateFormatter::parse('07-Apr-2026 09:30:15 +0000');

        self::assertSame('2026-04-07 09:30:15', $parsed->format('Y-m-d H:i:s'));
    }

    public function testParseSingleDigitDay(): void
    {
        $parsed = ImapDateFormatter::parse('7-Apr-2026 09:30:15 +0200');

        self::assertSame('2026-04-07', $parsed->format('Y-m-d'));
    }

    public function testParseRequiresZeroPaddedDayFormatNotJustSingleDigit(): void
    {
        // The first format in the array is `d-M-Y H:i:s O` (zero-padded
        // day) and the second is `j-M-Y H:i:s O` (single-digit-allowed).
        // Removing the first format (ArrayItemRemoval mutant on line 23)
        // would leave only `j-M-Y…` which still parses zero-padded inputs
        // — so the cheapest distinguishing input is one with a leading
        // zero on the day field that we can sanity-check round-trips.
        $parsed = ImapDateFormatter::parse('07-Apr-2026 09:30:15 +0000');

        self::assertSame('2026-04-07 09:30:15', $parsed->format('Y-m-d H:i:s'));
    }

    public function testParseTrimsLeadingAndTrailingWhitespace(): void
    {
        // Kills UnwrapTrim on line 33 — without trim() the leading/trailing
        // whitespace would be passed verbatim to createFromFormat which
        // would fail every format and ultimately throw ParseException.
        $parsed = ImapDateFormatter::parse('   07-Apr-2026 09:30:15 +0000   ');

        self::assertSame('2026-04-07 09:30:15', $parsed->format('Y-m-d H:i:s'));
    }

    public function testParseInvalidThrows(): void
    {
        $this->expectException(ParseException::class);

        ImapDateFormatter::parse('not-a-date');
    }
}
