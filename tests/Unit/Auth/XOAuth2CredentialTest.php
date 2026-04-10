<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\Contract\TokenRefresherInterface;
use D4ry\ImapClient\Auth\XOAuth2Credential;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \D4ry\ImapClient\Auth\XOAuth2Credential
 * @uses \D4ry\ImapClient\Protocol\Transceiver
 * @uses \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\ResponseParser
 * @uses \D4ry\ImapClient\Protocol\TagGenerator
 * @uses \D4ry\ImapClient\ValueObject\Tag
 * @uses \D4ry\ImapClient\Exception\CommandException
 */
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

    public function testAuthenticateUsesSaslIrHappyPath(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 OK Authenticated');

        (new XOAuth2Credential('u@example.com', 'tok'))->authenticate($transceiver);

        $expectedPayload = base64_encode("user=u@example.com\x01auth=Bearer tok\x01\x01");
        self::assertSame(
            ['A0001 AUTHENTICATE XOAUTH2 ' . $expectedPayload . "\r\n"],
            $connection->writes,
        );
    }

    public function testAuthenticateRefreshesTokenAfterFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 NO Token expired', 'A0002 OK Authenticated');

        $refresher = new class implements TokenRefresherInterface {
            public function refresh(string $currentToken): string
            {
                return 'tok-refreshed';
            }
        };

        $credential = new XOAuth2Credential('u@example.com', 'tok', $refresher);
        $credential->authenticate($transceiver);

        $firstPayload = base64_encode("user=u@example.com\x01auth=Bearer tok\x01\x01");
        $secondPayload = base64_encode("user=u@example.com\x01auth=Bearer tok-refreshed\x01\x01");

        self::assertSame(
            [
                'A0001 AUTHENTICATE XOAUTH2 ' . $firstPayload . "\r\n",
                'A0002 AUTHENTICATE XOAUTH2 ' . $secondPayload . "\r\n",
            ],
            $connection->writes,
        );
        self::assertSame('tok-refreshed', $credential->accessToken);
    }

    public function testAuthenticateThrowsWhenSaslIrFailsWithoutRefresher(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 NO Token expired');

        try {
            (new XOAuth2Credential('u@example.com', 'tok'))->authenticate($transceiver);
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            // Exact message + code assertions kill Concat, ConcatOperandRemoval
            // and Increment/Decrement mutants on the XOAUTH2 final-throw line.
            self::assertSame('XOAUTH2 authentication failed: Token expired', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(CommandException::class, $e->getPrevious());
        }
    }

    public function testAuthenticateContinuationFlowHappyPath(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::Imap4rev1]);

        $connection->queueLines('+ Ready', 'A0001 OK Authenticated');

        (new XOAuth2Credential('u@example.com', 'tok'))->authenticate($transceiver);

        $expectedPayload = base64_encode("user=u@example.com\x01auth=Bearer tok\x01\x01");
        self::assertSame(
            [
                "A0001 AUTHENTICATE XOAUTH2\r\n",
                $expectedPayload . "\r\n",
            ],
            $connection->writes,
        );
    }

    public function testAuthenticateContinuationFlowThrowsOnFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::Imap4rev1]);

        $connection->queueLines('+ Ready', 'A0001 NO Bad token');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('XOAUTH2 authentication failed: Bad token');

        (new XOAuth2Credential('u@example.com', 'tok'))->authenticate($transceiver);
    }

    public function testAuthenticateThrowsWhenRefreshAlsoFails(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 NO Token expired', 'A0002 NO Still bad');

        $refresher = new class implements TokenRefresherInterface {
            public function refresh(string $currentToken): string
            {
                return 'still-bad';
            }
        };

        try {
            (new XOAuth2Credential('u@example.com', 'tok', $refresher))->authenticate($transceiver);
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            // Exact message + code assertions kill Concat, ConcatOperandRemoval
            // and Increment/Decrement mutants on the XOAUTH2 post-refresh-throw.
            self::assertSame('XOAUTH2 authentication failed after token refresh: Still bad', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(CommandException::class, $e->getPrevious());
        }
    }

    /**
     * @param Capability[] $capabilities
     */
    private function seedCapabilities(Transceiver $transceiver, array $capabilities): void
    {
        $reflection = new ReflectionClass(Transceiver::class);
        $property = $reflection->getProperty('cachedCapabilities');
        $property->setValue($transceiver, $capabilities);
    }
}
