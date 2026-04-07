<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\Address;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Address::class)]
final class AddressTest extends TestCase
{
    public function testEmailJoinsMailboxAndHost(): void
    {
        $address = new Address('Jane Doe', 'jane', 'example.com');

        self::assertSame('jane@example.com', $address->email());
    }

    public function testToStringWithName(): void
    {
        $address = new Address('Jane Doe', 'jane', 'example.com');

        self::assertSame('"Jane Doe" <jane@example.com>', (string) $address);
    }

    public function testToStringWithoutName(): void
    {
        self::assertSame('jane@example.com', (string) new Address(null, 'jane', 'example.com'));
        self::assertSame('jane@example.com', (string) new Address('', 'jane', 'example.com'));
    }
}
