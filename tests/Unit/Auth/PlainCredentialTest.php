<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\PlainCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainCredential::class)]
final class PlainCredentialTest extends TestCase
{
    public function testMechanism(): void
    {
        self::assertSame('PLAIN', (new PlainCredential('u', 'p'))->mechanism());
    }

    public function testStoresCredentials(): void
    {
        $credential = new PlainCredential('user@example.com', 's3cret');

        self::assertSame('user@example.com', $credential->username);
        self::assertSame('s3cret', $credential->password);
    }

    public function testAuthenticateAgainstFakeTransceiver(): void
    {
        self::markTestIncomplete(
            'PlainCredential::authenticate() is tightly coupled to the concrete Transceiver class. '
            . 'A FakeTransceiver harness is needed to verify the SASL PLAIN payload (base64 of "\0user\0pass") '
            . 'is sent through both the SASL-IR and continuation flows.'
        );
    }
}
