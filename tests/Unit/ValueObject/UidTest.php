<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uid::class)]
final class UidTest extends TestCase
{
    public function testValueAndStringCast(): void
    {
        $uid = new Uid(123);

        self::assertSame(123, $uid->value);
        self::assertSame('123', (string) $uid);
    }
}
