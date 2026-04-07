<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\ValueObject\FlagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlagSet::class)]
final class FlagSetTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $set = new FlagSet();

        self::assertTrue($set->isEmpty());
        self::assertSame(0, $set->count());
        self::assertSame('()', $set->toImapString());
    }

    public function testNormalizesEnumAndStringFlagsAndDeduplicates(): void
    {
        $set = new FlagSet([Flag::Seen, '\Seen', Flag::Flagged]);

        self::assertSame(2, $set->count());
        self::assertTrue($set->has(Flag::Seen));
        self::assertTrue($set->has('\Flagged'));
        self::assertFalse($set->has(Flag::Answered));
    }

    public function testAddReturnsNewInstance(): void
    {
        $original = new FlagSet([Flag::Seen]);
        $added = $original->add(Flag::Flagged);

        self::assertSame(1, $original->count());
        self::assertSame(2, $added->count());
        self::assertTrue($added->has(Flag::Flagged));
        self::assertNotSame($original, $added);
    }

    public function testRemoveReturnsNewInstance(): void
    {
        $set = new FlagSet([Flag::Seen, Flag::Flagged]);
        $removed = $set->remove(Flag::Seen);

        self::assertTrue($set->has(Flag::Seen));
        self::assertFalse($removed->has(Flag::Seen));
        self::assertTrue($removed->has(Flag::Flagged));
    }

    public function testToImapStringFormatsFlags(): void
    {
        $set = new FlagSet([Flag::Seen, Flag::Flagged]);

        $result = $set->toImapString();
        self::assertStringStartsWith('(', $result);
        self::assertStringEndsWith(')', $result);
        self::assertStringContainsString('\\Seen', $result);
        self::assertStringContainsString('\\Flagged', $result);
    }
}
