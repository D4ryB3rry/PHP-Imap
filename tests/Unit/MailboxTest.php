<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Folder;
use D4ry\ImapClient\Idle\FlagsChangedEvent;
use D4ry\ImapClient\Idle\IdleEvent;
use D4ry\ImapClient\Idle\IdleHandlerInterface;
use D4ry\ImapClient\Idle\IdleHeartbeatEvent;
use D4ry\ImapClient\Idle\MessageExpungedEvent;
use D4ry\ImapClient\Idle\MessageReceivedEvent;
use D4ry\ImapClient\Idle\RecentCountEvent;
use D4ry\ImapClient\Mailbox;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\NamespaceInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(Mailbox::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(Folder::class)]
#[UsesClass(FolderCollection::class)]
#[UsesClass(NamespaceInfo::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\MailboxPath::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Tag::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\FlagSet::class)]
#[UsesClass(CapabilityException::class)]
#[UsesClass(CommandException::class)]
#[UsesClass(ConnectionException::class)]
#[UsesClass(IdleHeartbeatEvent::class)]
#[UsesClass(MessageReceivedEvent::class)]
#[UsesClass(MessageExpungedEvent::class)]
#[UsesClass(FlagsChangedEvent::class)]
#[UsesClass(RecentCountEvent::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\Command::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\CommandBuilder::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\Response::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\ResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\FetchResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\UntaggedResponse::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\TagGenerator::class)]
final class MailboxTest extends TestCase
{
    private function setCapabilities(Transceiver $transceiver, Capability ...$caps): void
    {
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, $caps);
    }

    private function makeMailbox(FakeConnection $connection, Capability ...$caps): array
    {
        $transceiver = new Transceiver($connection);
        $this->setCapabilities($transceiver, Capability::Imap4rev1, ...$caps);

        $ref = new ReflectionClass(Mailbox::class);
        $mailbox = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('transceiver');
        $prop->setValue($mailbox, $transceiver);

        return [$mailbox, $transceiver];
    }

    public function testFoldersParsesListResponseAndIsLazy(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasChildren) "/" "INBOX"',
            '* LIST (\Sent) "/" "Sent"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $folders = $mailbox->folders();
        self::assertSame([], $connection->writes, 'folders() must be lazy');

        self::assertSame(2, $folders->count());
        self::assertSame("A0001 LIST \"\" \"*\"\r\n", $connection->writes[0]);
        self::assertSame(SpecialUse::Sent, $folders->byName('Sent')?->specialUse());
    }

    public function testFolderReturnsParsedListEntry(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasNoChildren) "/" "Archive"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $folder = $mailbox->folder('Archive');

        self::assertSame('Archive', $folder->path()->path);
        self::assertSame("A0001 LIST \"\" Archive\r\n", $connection->writes[0]);
    }

    public function testFolderFallsBackToBareFolderWhenListIsEmpty(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK LIST done');

        [$mailbox] = $this->makeMailbox($connection);

        $folder = $mailbox->folder('NewFolder');

        self::assertSame('NewFolder', $folder->path()->path);
        self::assertNull($folder->specialUse());
    }

    public function testInboxDelegatesToFolder(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST () "/" "INBOX"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $inbox = $mailbox->inbox();

        self::assertSame('INBOX', $inbox->path()->path);
        self::assertSame("A0001 LIST \"\" INBOX\r\n", $connection->writes[0]);
    }

    public function testCapabilitiesDelegatesToTransceiver(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle, Capability::Move);

        self::assertContains(Capability::Idle, $mailbox->capabilities());
        self::assertTrue($mailbox->hasCapability(Capability::Idle));
        self::assertFalse($mailbox->hasCapability(Capability::Qresync));
    }

    public function testIdWithoutParams(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ID ("name" "TestServer")',
            'A0001 OK ID done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        $mailbox->id();

        self::assertSame("A0001 ID NIL\r\n", $connection->writes[0]);
    }

    public function testIdWithParamsWritesParameterTuples(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ID ("name" "Server")',
            'A0001 OK ID done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        $mailbox->id(['name' => 'TestClient', 'version' => '1.0']);

        self::assertSame(
            "A0001 ID (\"name\" \"TestClient\" \"version\" \"1.0\")\r\n",
            $connection->writes[0],
        );
    }

    public function testIdThrowsWhenCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->id();
    }

    public function testNamespaceParsesPersonalNamespace(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* NAMESPACE (("" "/")) NIL NIL',
            'A0001 OK NAMESPACE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Namespace);

        $ns = $mailbox->namespace();

        self::assertInstanceOf(NamespaceInfo::class, $ns);
        // Parser is best-effort; only check that the call succeeded and returned a NamespaceInfo.
        self::assertSame("A0001 NAMESPACE\r\n", $connection->writes[0]);
    }

    public function testNamespaceThrowsWhenCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->namespace();
    }

    public function testDisconnectSendsLogoutAndClosesConnection(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK LOGOUT done');

        [$mailbox] = $this->makeMailbox($connection);

        $mailbox->disconnect();

        self::assertSame("A0001 LOGOUT\r\n", $connection->writes[0]);
        self::assertFalse($connection->isConnected());
    }

    public function testDisconnectSwallowsCommandException(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO logout failed');

        [$mailbox] = $this->makeMailbox($connection);

        $mailbox->disconnect();

        self::assertFalse($connection->isConnected());
    }

    public function testGetTransceiverReturnsInjectedInstance(): void
    {
        $connection = new FakeConnection();
        [$mailbox, $transceiver] = $this->makeMailbox($connection);

        self::assertSame($transceiver, $mailbox->getTransceiver());
    }

    public function testIdleWithCallableReceivesEventAndStops(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ idling',
            '* 5 EXISTS',
            'A0001 OK IDLE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $received = [];
        $mailbox->idle(function (IdleEvent $event) use (&$received): bool {
            $received[] = $event;
            return false; // stop after first event
        }, timeout: 5.0);

        self::assertCount(1, $received);
        self::assertInstanceOf(MessageReceivedEvent::class, $received[0]);
        self::assertSame(5, $received[0]->messageCount);

        self::assertSame("A0001 IDLE\r\n", $connection->writes[0]);
        self::assertSame("DONE\r\n", $connection->writes[1]);
    }

    public function testIdleThrowsWhenIdleCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->idle(fn() => false);
    }

    public function testIdleWithHandlerInterfaceDispatchesAllEventTypes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ idling',
            '* 1 EXISTS',
            '* 2 EXPUNGE',
            '* 3 RECENT',
            '* 4 FETCH (FLAGS (\Seen))',
            '* OK Still here',
            'A0001 OK IDLE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $handler = new class implements IdleHandlerInterface {
            public int $received = 0;
            public int $expunged = 0;
            public int $recent = 0;
            public int $flags = 0;
            public int $heartbeat = 0;

            public function onMessageReceived(MessageReceivedEvent $event): bool
            {
                $this->received++;
                return true;
            }

            public function onMessageExpunged(MessageExpungedEvent $event): bool
            {
                $this->expunged++;
                return true;
            }

            public function onFlagsChanged(FlagsChangedEvent $event): bool
            {
                $this->flags++;
                return true;
            }

            public function onRecentCount(RecentCountEvent $event): bool
            {
                $this->recent++;
                return true;
            }

            public function onHeartbeat(IdleHeartbeatEvent $event): bool
            {
                $this->heartbeat++;
                return false; // stop after the heartbeat
            }
        };

        $mailbox->idle($handler, timeout: 5.0);

        self::assertSame(1, $handler->received);
        self::assertSame(1, $handler->expunged);
        self::assertSame(1, $handler->recent);
        self::assertSame(1, $handler->flags);
        self::assertSame(1, $handler->heartbeat);
    }

    public function testIdleThrowsConnectionExceptionWhenServerDoesNotSendContinuation(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO idle not allowed');

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $this->expectException(ConnectionException::class);
        $mailbox->idle(fn() => false);
    }
}
