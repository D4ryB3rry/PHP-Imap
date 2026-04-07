<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\Contract\TokenRefresherInterface;
use D4ry\ImapClient\Auth\XOAuth2Credential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XOAuth2Credential::class)]
final class XOAuth2CredentialTest extends TestCase
{
    public function testMechanism(): void
    {
        self::assertSame('XOAUTH2', (new XOAuth2Credential('u@example.com', 'token'))->mechanism());
    }

    public function testStoresFields(): void
    {
        $refresher = $this->createStub(TokenRefresherInterface::class);
        $credential = new XOAuth2Credential('u@example.com', 'tok', $refresher);

        self::assertSame('u@example.com', $credential->email);
        self::assertSame('tok', $credential->accessToken);
        self::assertSame($refresher, $credential->tokenRefresher);
    }

    public function testTokenRefresherInterfaceContract(): void
    {
        $refresher = new class implements TokenRefresherInterface {
            public function refresh(string $currentToken): string
            {
                return 'refreshed-' . $currentToken;
            }
        };

        self::assertSame('refreshed-old', $refresher->refresh('old'));
    }

    public function testAuthenticateAgainstFakeTransceiver(): void
    {
        self::markTestIncomplete(
            'XOAuth2Credential::authenticate() requires a Transceiver double to verify both the SASL-IR happy '
            . 'path and the token-refresh fallback. Expected payload: base64("user={email}\x01auth=Bearer {token}\x01\x01").'
        );
    }
}
