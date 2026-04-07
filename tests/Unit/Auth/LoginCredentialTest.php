<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\LoginCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginCredential::class)]
final class LoginCredentialTest extends TestCase
{
    public function testMechanism(): void
    {
        self::assertSame('LOGIN', new LoginCredential('u', 'p')->mechanism());
    }

    public function testStoresCredentials(): void
    {
        $credential = new LoginCredential('user', 'pass');

        self::assertSame('user', $credential->username);
        self::assertSame('pass', $credential->password);
    }

    public function testAuthenticateAgainstFakeTransceiver(): void
    {
        self::markTestIncomplete(
            'LoginCredential::authenticate() requires a Transceiver double to assert the LOGIN command '
            . 'is issued with properly quoted username/password arguments.'
        );
    }
}
