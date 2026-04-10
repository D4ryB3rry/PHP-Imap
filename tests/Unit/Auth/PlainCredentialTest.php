<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\PlainCredential;
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
 * @covers \D4ry\ImapClient\Auth\PlainCredential
 * @uses \D4ry\ImapClient\Protocol\Transceiver
 * @uses \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\ResponseParser
 * @uses \D4ry\ImapClient\Protocol\TagGenerator
 * @uses \D4ry\ImapClient\ValueObject\Tag
 * @uses \D4ry\ImapClient\Exception\CommandException
 */
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

    public function testAuthenticateUsesSaslIrWhenCapabilityPresent(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 OK Authenticated');

        (new PlainCredential('user', 'pass'))->authenticate($transceiver);

        $expectedPayload = base64_encode("\x00user\x00pass");
        self::assertSame(
            ['A0001 AUTHENTICATE PLAIN ' . $expectedPayload . "\r\n"],
            $connection->writes,
        );
    }

    public function testAuthenticateFallsBackToContinuationFlow(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::Imap4rev1]);

        $connection->queueLines('+ Ready', 'A0001 OK Authenticated');

        (new PlainCredential('user', 'pass'))->authenticate($transceiver);

        $expectedPayload = base64_encode("\x00user\x00pass");
        self::assertSame(
            [
                "A0001 AUTHENTICATE PLAIN\r\n",
                $expectedPayload . "\r\n",
            ],
            $connection->writes,
        );
    }

    public function testAuthenticateThrowsOnFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::SaslIr]);

        $connection->queueLines('A0001 NO Bad credentials');

        try {
            (new PlainCredential('user', 'pass'))->authenticate($transceiver);
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            // Exact-message + exact-code assertions kill Concat,
            // ConcatOperandRemoval and Increment/Decrement mutants on the
            // AuthenticationException construction in PlainCredential.
            self::assertSame('PLAIN authentication failed: Bad credentials', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(CommandException::class, $e->getPrevious());
        }
    }

    public function testAuthenticateThrowsOnContinuationFlowFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);
        $this->seedCapabilities($transceiver, [Capability::Imap4rev1]);

        $connection->queueLines('+ Ready', 'A0001 NO Bad credentials');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('PLAIN authentication failed: Bad credentials');

        (new PlainCredential('user', 'pass'))->authenticate($transceiver);
    }

    /**
     * @param Capability[] $capabilities
     */
    private function seedCapabilities(Transceiver $transceiver, array $capabilities): void
    {
        $reflection = new ReflectionClass(Transceiver::class);
        $property = $reflection->getProperty('cachedCapabilities');
        $property->setAccessible(true);
        $property->setValue($transceiver, $capabilities);
    }
}
