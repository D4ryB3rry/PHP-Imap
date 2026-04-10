<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Enum;

use D4ry\ImapClient\Enum\Flag;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Enum\Flag
 */
final class FlagTest extends TestCase
{
    public function testConstantsMatchExpectedValues(): void
    {
        self::assertSame('\\Seen', Flag::Seen);
        self::assertSame('\\Answered', Flag::Answered);
        self::assertSame('\\Flagged', Flag::Flagged);
        self::assertSame('\\Deleted', Flag::Deleted);
        self::assertSame('\\Draft', Flag::Draft);
        self::assertSame('\\Recent', Flag::Recent);
    }

    public function testFromReturnsValue(): void
    {
        self::assertSame(Flag::Seen, Flag::from('\\Seen'));
    }

    public function testTryFromReturnsNullForUnknown(): void
    {
        self::assertNull(Flag::tryFrom('\\Unknown'));
    }

    public function testCasesReturnsAll(): void
    {
        self::assertCount(6, Flag::cases());
    }
}
