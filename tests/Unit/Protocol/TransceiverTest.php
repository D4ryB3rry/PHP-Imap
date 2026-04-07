<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transceiver::class)]
#[UsesClass(Command::class)]
#[UsesClass(Response::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(UntaggedResponse::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
#[UsesClass(CommandException::class)]
#[UsesClass(CapabilityException::class)]
final class TransceiverTest extends TestCase
{
    public function testReadGreetingDelegatesToParser(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* OK ready');

        $greeting = (new Transceiver($connection))->readGreeting();

        self::assertSame('OK', $greeting->type);
    }

    public function testCommandWritesCompiledLine(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NOOP done');

        (new Transceiver($connection))->command('NOOP');

        self::assertSame(["A0001 NOOP\r\n"], $connection->writes);
    }

    public function testCommandWithArgumentsWritesAllParts(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK SELECT done');

        (new Transceiver($connection))->command('SELECT', '"INBOX"');

        self::assertSame(["A0001 SELECT \"INBOX\"\r\n"], $connection->writes);
    }

    public function testCommandReturnsResponseAndUsesNextTag(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK first',
            'A0002 OK second',
        );

        $transceiver = new Transceiver($connection);
        $first = $transceiver->command('NOOP');
        $second = $transceiver->command('NOOP');

        self::assertSame('A0001', $first->tag);
        self::assertSame('A0002', $second->tag);
    }

    public function testCommandThrowsOnNoStatus(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO not allowed');

        $this->expectException(CommandException::class);

        try {
            (new Transceiver($connection))->command('SELECT', 'INBOX');
        } catch (CommandException $e) {
            self::assertSame('A0001', $e->tag);
            self::assertSame('SELECT', $e->command);
            self::assertSame('not allowed', $e->responseText);
            self::assertSame('NO', $e->status);
            throw $e;
        }
    }

    public function testCommandThrowsOnBadStatus(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 BAD syntax error');

        $this->expectException(CommandException::class);

        (new Transceiver($connection))->command('FOO');
    }

    public function testCommandRawSendsRawLineWithTag(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK done');

        (new Transceiver($connection))->commandRaw('UID FETCH 1 (FLAGS)');

        self::assertSame(["A0001 UID FETCH 1 (FLAGS)\r\n"], $connection->writes);
    }

    public function testCommandRawThrowsCommandExceptionOnFailure(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO denied');

        try {
            (new Transceiver($connection))->commandRaw('UID FETCH 1 (FLAGS)');
            self::fail('Expected CommandException');
        } catch (CommandException $e) {
            self::assertSame('UID', $e->command);
            self::assertSame('NO', $e->status);
            self::assertSame('denied', $e->responseText);
        }
    }

    public function testSendAuthenticateCommandWritesAndReadsResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK authenticated');

        $response = (new Transceiver($connection))->sendAuthenticateCommand('PLAIN');

        self::assertSame(["A0001 AUTHENTICATE PLAIN\r\n"], $connection->writes);
        self::assertTrue($response->isOk());
    }

    public function testSendAuthenticateDoesNotThrowOnFailure(): void
    {
        // Authenticate is special: it must return failed responses so credentials
        // can convert them into AuthenticationException themselves.
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO bad credentials');

        $response = (new Transceiver($connection))->sendAuthenticateCommand('PLAIN');

        self::assertSame(ResponseStatus::No, $response->status);
    }

    public function testSendContinuationDataAppendsCrlf(): void
    {
        $connection = new FakeConnection();

        (new Transceiver($connection))->sendContinuationData('payload');

        self::assertSame(["payload\r\n"], $connection->writes);
    }

    public function testReadResponseForTagDelegates(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A1234 OK done');

        $response = (new Transceiver($connection))->readResponseForTag('A1234');

        self::assertSame('A1234', $response->tag);
    }

    public function testCapabilitiesPopulatesFromUntaggedResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE MOVE CONDSTORE',
            'A0001 OK CAPABILITY done',
        );

        $caps = (new Transceiver($connection))->capabilities();

        self::assertContains(Capability::Idle, $caps);
        self::assertContains(Capability::Move, $caps);
        self::assertContains(Capability::Condstore, $caps);
    }

    public function testCapabilitiesIsCachedAcrossCalls(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE MOVE',
            'A0001 OK done',
        );

        $transceiver = new Transceiver($connection);
        $first = $transceiver->capabilities();
        $second = $transceiver->capabilities();

        self::assertSame($first, $second);
        // Only one CAPABILITY command should have been written.
        self::assertCount(1, $connection->writes);
    }

    public function testCapabilitiesPopulatedFromTaggedResponseCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK [CAPABILITY IDLE MOVE] done');

        $caps = (new Transceiver($connection))->capabilities();

        self::assertContains(Capability::Idle, $caps);
        self::assertContains(Capability::Move, $caps);
    }

    public function testCapabilitiesPopulatedFromUntaggedOkResponseCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* OK [CAPABILITY IDLE MOVE] hello',
            'A0001 OK done',
        );

        $caps = (new Transceiver($connection))->capabilities();

        self::assertContains(Capability::Idle, $caps);
        self::assertContains(Capability::Move, $caps);
    }

    public function testHasCapabilityReturnsTrueForKnownCapability(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE',
            'A0001 OK done',
        );

        $transceiver = new Transceiver($connection);

        self::assertTrue($transceiver->hasCapability(Capability::Idle));
        self::assertFalse($transceiver->hasCapability(Capability::Move));
    }

    public function testRequireCapabilityThrowsWhenMissing(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE',
            'A0001 OK done',
        );

        $this->expectException(CapabilityException::class);

        (new Transceiver($connection))->requireCapability(Capability::Move);
    }

    public function testRequireCapabilityPassesWhenPresent(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE MOVE',
            'A0001 OK done',
        );

        (new Transceiver($connection))->requireCapability(Capability::Move);

        $this->addToAssertionCount(1);
    }

    public function testRefreshCapabilitiesClearsCacheAndReFetches(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IDLE',
            'A0001 OK done',
            '* CAPABILITY IDLE MOVE',
            'A0002 OK done',
        );

        $transceiver = new Transceiver($connection);
        $transceiver->capabilities();

        self::assertFalse($transceiver->hasCapability(Capability::Move));

        $transceiver->refreshCapabilities();

        self::assertTrue($transceiver->hasCapability(Capability::Move));
        self::assertCount(2, $connection->writes);
    }

    public function testSelectedMailboxGetterAndSetter(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        self::assertNull($transceiver->selectedMailbox);

        $transceiver->selectedMailbox = 'INBOX';

        self::assertSame('INBOX', $transceiver->selectedMailbox);
    }

    public function testIsUtf8EnabledDefaultFalse(): void
    {
        $connection = new FakeConnection();

        self::assertFalse((new Transceiver($connection))->isUtf8Enabled());
    }

    public function testEnabledUntaggedSetsUtf8Flag(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ENABLED UTF8=ACCEPT',
            'A0001 OK done',
        );

        $transceiver = new Transceiver($connection);
        $transceiver->command('ENABLE', 'UTF8=ACCEPT');

        self::assertTrue($transceiver->isUtf8Enabled());
        self::assertTrue($transceiver->utf8Enabled);
    }

    public function testEnabledUntaggedDoesNotEnableForOtherExtensions(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ENABLED CONDSTORE',
            'A0001 OK done',
        );

        $transceiver = new Transceiver($connection);
        $transceiver->command('ENABLE', 'CONDSTORE');

        self::assertFalse($transceiver->isUtf8Enabled());
    }

    public function testGetConnectionReturnsInjectedConnection(): void
    {
        $connection = new FakeConnection();
        $transceiver = new Transceiver($connection);

        self::assertSame($connection, $transceiver->getConnection());
    }

    public function testGetTagGeneratorReturnsSharedGenerator(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK done');

        $transceiver = new Transceiver($connection);
        $transceiver->command('NOOP');

        // Subsequent .next() should produce A0002, proving it's the same instance.
        self::assertSame('A0002', $transceiver->getTagGenerator()->next()->value);
    }

    public function testCapabilityIgnoresUnknownStrings(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY MOVE SOMETHING-UNKNOWN IDLE',
            'A0001 OK done',
        );

        $caps = (new Transceiver($connection))->capabilities();

        // Unknown capabilities are silently dropped, known ones survive.
        self::assertContains(Capability::Move, $caps);
        self::assertContains(Capability::Idle, $caps);
        foreach ($caps as $cap) {
            self::assertInstanceOf(Capability::class, $cap);
        }
    }
}
