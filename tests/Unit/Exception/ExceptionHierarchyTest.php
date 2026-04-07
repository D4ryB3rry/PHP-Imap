<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Exception;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\ImapException;
use D4ry\ImapClient\Exception\ParseException;
use D4ry\ImapClient\Exception\ProtocolException;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use D4ry\ImapClient\Exception\TimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImapException::class)]
#[CoversClass(CommandException::class)]
#[CoversClass(CapabilityException::class)]
#[CoversClass(ReadOnlyCollectionException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    public function testImapExceptionExtendsRuntime(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new ImapException('boom'));
    }

    public function testSubclassesExtendImapException(): void
    {
        self::assertInstanceOf(ImapException::class, new ConnectionException('x'));
        self::assertInstanceOf(ImapException::class, new AuthenticationException('x'));
        self::assertInstanceOf(ImapException::class, new ParseException('x'));
        self::assertInstanceOf(ImapException::class, new ProtocolException('x'));
        self::assertInstanceOf(ImapException::class, new TimeoutException('x'));
    }

    public function testCommandExceptionRetainsContext(): void
    {
        $e = new CommandException('A0001', 'SELECT', 'No such mailbox', 'NO');

        self::assertSame('A0001', $e->tag);
        self::assertSame('SELECT', $e->command);
        self::assertSame('No such mailbox', $e->responseText);
        self::assertSame('NO', $e->status);
        self::assertStringContainsString('SELECT', $e->getMessage());
        self::assertStringContainsString('NO', $e->getMessage());
    }

    public function testCapabilityExceptionRetainsCapability(): void
    {
        $e = new CapabilityException(Capability::Idle);

        self::assertSame(Capability::Idle, $e->capability);
        self::assertStringContainsString('IDLE', $e->getMessage());
    }

    public function testReadOnlyCollectionExceptionIsLogicException(): void
    {
        $e = new ReadOnlyCollectionException();

        self::assertInstanceOf(\LogicException::class, $e);
        self::assertSame('This collection is read-only.', $e->getMessage());
    }
}
