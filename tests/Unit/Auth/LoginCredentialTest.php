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
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Auth\LoginCredential
 * @uses \D4ry\ImapClient\Protocol\Transceiver
 * @uses \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\ResponseParser
 * @uses \D4ry\ImapClient\Protocol\TagGenerator
 * @uses \D4ry\ImapClient\ValueObject\Tag
 * @uses \D4ry\ImapClient\Exception\CommandException
 */
final class LoginCredentialTest extends TestCase
{
    public function testMechanism(): void
    {
        self::assertSame('LOGIN', (new LoginCredential('u', 'p'))->mechanism());
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

    public function testAuthenticateEscapesBackslashAndDoubleQuoteInBothFields(): void
    {
        // Both username and password contain BOTH a backslash and a double
        // quote, so the str_replace() pair on each side has to escape both
        // characters: `\` → `\\` and `"` → `\"`. Removing either array item
        // (ArrayItemRemoval), or unwrapping the whole str_replace
        // (UnwrapStrReplace), produces a different LOGIN line on the wire —
        // the byte-exact assertion below catches all three mutants.
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        $connection->queueLines('A0001 OK Logged in');

        (new LoginCredential('us\\er"x', 'p\\a"ss'))->authenticate($transceiver);

        self::assertSame(
            ["A0001 LOGIN \"us\\\\er\\\"x\" \"p\\\\a\\\"ss\"\r\n"],
            $connection->writes,
        );
    }

    public function testAuthenticateThrowsOnFailure(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        $connection->queueLines('A0001 NO Login failed');

        try {
            (new LoginCredential('user', 'pass'))->authenticate($transceiver);
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            // Exact message kills Concat (reorder), ConcatOperandRemoval and
            // any future Concat mutants on the message construction.
            self::assertSame('LOGIN authentication failed: Login failed', $e->getMessage());
            // Exact code kills Increment/Decrement on the literal `0`.
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(CommandException::class, $e->getPrevious());
        }
    }
}
