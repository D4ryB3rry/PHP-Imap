<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Auth;

use D4ry\ImapClient\Auth\LoginCredential;
use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginCredential::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(Command::class)]
#[UsesClass(Response::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
#[UsesClass(CommandException::class)]
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

    public function testAuthenticateIssuesQuotedLoginCommand(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        $connection->queueLines('A0001 OK Logged in');

        (new LoginCredential('user', 'pa"ss'))->authenticate($transceiver);

        self::assertSame(
            ["A0001 LOGIN \"user\" \"pa\\\"ss\"\r\n"],
            $connection->writes,
        );
    }

    public function testAuthenticateThrowsOnFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        $connection->queueLines('A0001 NO Login failed');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('LOGIN authentication failed');

        (new LoginCredential('user', 'pass'))->authenticate($transceiver);
    }
}
