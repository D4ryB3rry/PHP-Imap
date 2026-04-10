<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\SequenceSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\SequenceSet
 */
final class SequenceSetTest extends TestCase
{
    public function testSingle(): void
    {
        self::assertSame('42', (string) SequenceSet::single(42));
    }

    public function testRange(): void
    {
        self::assertSame('1:10', (string) SequenceSet::range(1, 10));
    }

    public function testAll(): void
    {
        self::assertSame('1:*', (string) SequenceSet::all());
    }

    public function testFromArrayCollapsesContiguousRanges(): void
    {
        $set = SequenceSet::fromArray([3, 1, 2, 5, 7, 8, 9]);

        self::assertSame('1:3,5,7:9', (string) $set);
    }

    public function testFromArraySingleNumber(): void
    {
        self::assertSame('5', (string) SequenceSet::fromArray([5]));
    }
}
