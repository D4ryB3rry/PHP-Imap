<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Exception\ParseException;
use D4ry\ImapClient\Support\ImapDateFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImapDateFormatter::class)]
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

    public function testParseInvalidThrows(): void
    {
        $this->expectException(ParseException::class);

        ImapDateFormatter::parse('not-a-date');
    }
}
