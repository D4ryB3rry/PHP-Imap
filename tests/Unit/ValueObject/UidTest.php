<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\Uid
 */
final class UidTest extends TestCase
{
    public function testValueAndStringCast(): void
    {
        $uid = new Uid(123);

        self::assertSame(123, $uid->value);
        self::assertSame('123', (string) $uid);
    }
}
