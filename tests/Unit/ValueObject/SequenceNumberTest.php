<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\SequenceNumber;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\SequenceNumber
 */
final class SequenceNumberTest extends TestCase
{
    public function testStoresValue(): void
    {
        $seq = new SequenceNumber(42);

        self::assertSame(42, $seq->value);
    }

    public function testToStringReturnsValueAsString(): void
    {
        self::assertSame('1', (string) new SequenceNumber(1));
        self::assertSame('999', (string) new SequenceNumber(999));
    }

}
