<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Folder;
use D4ry\ImapClient\Mailbox;
use D4ry\ImapClient\Notify\AbstractNotifyHandler;
use D4ry\ImapClient\Notify\AnnotationChangeEvent;
use D4ry\ImapClient\Notify\EventGroup;
use D4ry\ImapClient\Notify\FlagChangeEvent;
use D4ry\ImapClient\Notify\MailboxFilter;
use D4ry\ImapClient\Notify\MailboxMetadataChangeEvent;
use D4ry\ImapClient\Notify\MailboxNameEvent;
use D4ry\ImapClient\Notify\MailboxStatusEvent;
use D4ry\ImapClient\Notify\MessageExpungedEvent;
use D4ry\ImapClient\Notify\MessageNewEvent;
use D4ry\ImapClient\Notify\NotifyDispatcher;
use D4ry\ImapClient\Notify\NotifyEvent;
use D4ry\ImapClient\Notify\NotifyEventType;
use D4ry\ImapClient\Notify\NotifyListener;
use D4ry\ImapClient\Notify\ServerMetadataChangeEvent;
use D4ry\ImapClient\Notify\SubscriptionChangeEvent;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\Response\FetchResponseParser;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\Tests\Unit\Support\TimeoutOnceConnection;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[CoversClass(Mailbox::class)]
#[CoversClass(NotifyListener::class)]
#[UsesClass(NotifyDispatcher::class)]
#[UsesClass(EventGroup::class)]
#[UsesClass(MailboxFilter::class)]
#[UsesClass(NotifyEventType::class)]
#[UsesClass(NotifyEvent::class)]
#[UsesClass(AbstractNotifyHandler::class)]
#[UsesClass(MessageNewEvent::class)]
#[UsesClass(MessageExpungedEvent::class)]
#[UsesClass(FlagChangeEvent::class)]
#[UsesClass(MailboxNameEvent::class)]
#[UsesClass(SubscriptionChangeEvent::class)]
#[UsesClass(AnnotationChangeEvent::class)]
#[UsesClass(MailboxMetadataChangeEvent::class)]
#[UsesClass(ServerMetadataChangeEvent::class)]
#[UsesClass(MailboxStatusEvent::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(Folder::class)]
#[UsesClass(Command::class)]
#[UsesClass(CommandBuilder::class)]
#[UsesClass(Response::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(FetchResponseParser::class)]
#[UsesClass(UntaggedResponse::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
#[UsesClass(FlagSet::class)]
#[UsesClass(CapabilityException::class)]
#[UsesClass(\D4ry\ImapClient\Exception\CommandException::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\MailboxPath::class)]
#[UsesClass(\D4ry\ImapClient\Exception\TimeoutException::class)]
final class MailboxNotifyTest extends TestCase
{
    /**
     * @return array{0: Mailbox, 1: Transceiver}
     */
    private function makeMailbox(FakeConnection|TimeoutOnceConnection $connection, Capability ...$caps): array
    {
        $transceiver = new Transceiver($connection);

        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, [Capability::Imap4rev1, ...$caps]);

        $ref = new ReflectionClass(Mailbox::class);
        $mailbox = $ref->newInstanceWithoutConstructor();
        $tProp = $ref->getProperty('transceiver');
        $tProp->setValue($mailbox, $transceiver);

        return [$mailbox, $transceiver];
    }

    public function testNotifySetWireFormatEmitsSingleEventGroup(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NOTIFY completed');

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $mailbox->notify([
            new EventGroup(
                MailboxFilter::inboxes(),
                [NotifyEventType::MessageNew, NotifyEventType::MessageExpunge, NotifyEventType::FlagChange],
            ),
        ]);

        self::assertSame(
            ["A0001 NOTIFY SET (inboxes (MessageNew MessageExpunge FlagChange))\r\n"],
            $connection->writes,
        );
    }

    public function testNotifySetWithStatusAndMessageNewFetchAtt(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NOTIFY completed');

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $mailbox->notify([
            new EventGroup(
                MailboxFilter::inboxes(),
                [NotifyEventType::MessageNew, NotifyEventType::MessageExpunge],
                ['UID', 'FLAGS'],
            ),
            new EventGroup(
                MailboxFilter::subtree(['Archive']),
                [NotifyEventType::MessageNew],
            ),
        ], includeStatus: true);

        self::assertSame(
            ["A0001 NOTIFY SET STATUS (inboxes (MessageNew (UID FLAGS) MessageExpunge)) (subtree Archive (MessageNew))\r\n"],
            $connection->writes,
        );
    }

    public function testNotifyNoneEmitsBareCommand(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NOTIFY NONE completed');

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $mailbox->notifyNone();

        self::assertSame(["A0001 NOTIFY NONE\r\n"], $connection->writes);
    }

    public function testNotifyWithoutCapabilityThrows(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);

        $mailbox->notify([
            new EventGroup(MailboxFilter::inboxes(), [NotifyEventType::MessageNew]),
        ]);
    }

    public function testNotifyRejectsEmptyGroupList(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $this->expectException(\InvalidArgumentException::class);

        $mailbox->notify([]);
    }

    public function testNotifyRejectsNonEventGroupElement(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $this->expectException(\InvalidArgumentException::class);
        // Pin the full message (including the get_debug_type(...) tail) to
        // kill Concat / ConcatOperandRemoval mutants on the throw message at
        // Mailbox.php:597.
        $this->expectExceptionMessage(
            'notify() expects EventGroup[] — got string',
        );

        /** @phpstan-ignore-next-line  deliberate invalid input */
        $mailbox->notify(['not-an-eventgroup']);
    }

    public function testNotifyNoneThrowsWhenCapabilityMissing(): void
    {
        // Kills MethodCallRemoval on $this->transceiver->requireCapability(...)
        // inside notifyNone() (Mailbox.php:614).
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection); // no Notify cap

        $this->expectException(CapabilityException::class);
        $mailbox->notifyNone();
    }

    public function testNotifyNoneClearsPreviouslyRegisteredUntaggedHook(): void
    {
        // Kills MethodCallRemoval on $this->transceiver->setUntaggedHook(null)
        // inside notifyNone() (Mailbox.php:617). Without the clear, untagged
        // pushes would continue to reach the previously-installed hook.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY NONE completed',
            '* STATUS Archive (MESSAGES 1)',
            'A0002 OK NOOP completed',
        );

        [$mailbox, $transceiver] = $this->makeMailbox($connection, Capability::Notify);

        $count = 0;
        $mailbox->setNotifyHandler(function (NotifyEvent $e) use (&$count): void {
            $count++;
        });

        $mailbox->notifyNone();

        $transceiver->command('NOOP');

        self::assertSame(0, $count, 'notifyNone() must clear the passive untagged hook');
    }

    public function testListenForNotificationsDefaultTimeoutIs300Seconds(): void
    {
        // Kills Increment/Decrement mutants on the float $timeout = 300
        // default at Mailbox.php:636.
        $ref = new \ReflectionMethod(Mailbox::class, 'listenForNotifications');
        self::assertSame(300.0, $ref->getParameters()[1]->getDefaultValue());
    }

    public function testListenToFoldersDefaultTimeoutIs300Seconds(): void
    {
        // Kills Increment/Decrement mutants on the float $timeout = 300
        // default at Mailbox.php:648.
        $ref = new \ReflectionMethod(Mailbox::class, 'listenToFolders');
        self::assertSame(300.0, $ref->getParameters()[2]->getDefaultValue());
    }

    public function testListenToFoldersRejectsEmptyList(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $this->expectException(\InvalidArgumentException::class);
        // Pin the exact message — without it the Throw_ mutant (drop the
        // throw keyword) would let execution fall through into
        // NotifyListener::listenToMailboxes which has its own empty-list
        // guard with a DIFFERENT message, so `expectException` alone would
        // not distinguish. Pinning listenToFolders()'s wording kills the
        // mutant.
        $this->expectExceptionMessage('listenToFolders() requires at least one folder');

        $mailbox->listenToFolders(folders: [], handler: function () {
            return true;
        });
    }

    public function testListenToFoldersAcceptsFolderInterfaceInstances(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 9 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        [$mailbox, $transceiver] = $this->makeMailbox($connection, Capability::Notify);

        $folder = new Folder(
            transceiver: $transceiver,
            mailboxPath: new \D4ry\ImapClient\ValueObject\MailboxPath('Archive'),
        );

        $handler = new class extends AbstractNotifyHandler {
            public function onMessageNew(MessageNewEvent $event): bool
            {
                return false;
            }
        };

        $mailbox->listenToFolders([$folder], $handler, timeout: 5);

        self::assertSame(
            "A0001 NOTIFY SET (mailboxes Archive (MessageNew MessageExpunge FlagChange))\r\n",
            $connection->writes[0],
        );
    }

    public function testListenToFoldersTeardownSwallowsFailure(): void
    {
        // Final NOTIFY NONE returns BAD — the teardown catches Throwable and
        // returns normally.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 1 EXISTS',
            'A0002 BAD unsupported',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public function onMessageNew(MessageNewEvent $event): bool
            {
                return false;
            }
        };

        $mailbox->listenToFolders(folders: ['Archive'], handler: $handler, timeout: 5);

        // No exception escaped the call — that's the whole point.
        self::assertSame("A0002 NOTIFY NONE\r\n", $connection->writes[1]);
    }

    public function testPassiveHandlerReceivesUntaggedPushedDuringUnrelatedCommand(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* STATUS Archive (MESSAGES 3 UIDNEXT 10)',
            'A0002 OK NOOP completed',
        );

        [$mailbox, $transceiver] = $this->makeMailbox($connection, Capability::Notify);

        /** @var list<NotifyEvent> $seen */
        $seen = [];
        $mailbox->setNotifyHandler(function (NotifyEvent $e) use (&$seen): void {
            $seen[] = $e;
        });

        $mailbox->notify([
            new EventGroup(MailboxFilter::subtree(['Archive']), [NotifyEventType::MessageNew]),
        ]);

        $transceiver->command('NOOP');

        self::assertCount(1, $seen);
        self::assertInstanceOf(MailboxStatusEvent::class, $seen[0]);
        self::assertSame('Archive', $seen[0]->mailbox);
        self::assertSame(3, $seen[0]->attributes['MESSAGES']);
    }

    public function testSetNotifyHandlerNullClearsPassiveDispatch(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS Archive (MESSAGES 1)',
            'A0001 OK NOOP completed',
        );

        [$mailbox, $transceiver] = $this->makeMailbox($connection, Capability::Notify);

        $count = 0;
        $mailbox->setNotifyHandler(function (NotifyEvent $e) use (&$count): void {
            $count++;
        });
        $mailbox->setNotifyHandler(null);

        $transceiver->command('NOOP');

        self::assertSame(0, $count);
    }

    public function testListenForNotificationsDispatchesPushesInOrder(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 7 EXISTS',
            '* 3 EXPUNGE',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        /** @var list<NotifyEvent> $seen */
        $seen = [];
        $handler = new class ($seen) extends AbstractNotifyHandler {
            /** @param list<NotifyEvent> $seen */
            public function __construct(public array &$seen) {}

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->seen[] = $event;
                return true;
            }

            public function onMessageExpunged(MessageExpungedEvent $event): bool
            {
                $this->seen[] = $event;
                // Stop after the expunge so the loop exits cleanly without
                // exhausting the fake connection's read queue.
                return false;
            }
        };

        $mailbox->listenForNotifications($handler, timeout: 5);

        self::assertCount(2, $seen);
        self::assertInstanceOf(MessageNewEvent::class, $seen[0]);
        self::assertSame(7, $seen[0]->sequenceNumber);
        self::assertInstanceOf(MessageExpungedEvent::class, $seen[1]);
        self::assertSame(3, $seen[1]->sequenceNumber);
    }

    public function testListenForNotificationsTimeoutExceptionContinuesLoop(): void
    {
        // First readLine throws TimeoutException (continue branch), second
        // returns EXISTS, handler false → break.
        $fake = new FakeConnection();
        $fake->queueLines('* 4 EXISTS');

        $connection = new TimeoutOnceConnection($fake, throwOnCall: 1);

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public int $seenSeq = 0;

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->seenSeq = $event->sequenceNumber;
                return false;
            }
        };

        $mailbox->listenForNotifications($handler, timeout: 5);

        self::assertSame(4, $handler->seenSeq);
    }

    public function testListenForNotificationsBreaksWhenHandlerReturnsFalse(): void
    {
        $fake = new FakeConnection();
        $fake->queueLines(
            '* 1 EXISTS',
            '* 2 EXISTS', // should not be delivered — handler breaks after #1
        );

        [$mailbox] = $this->makeMailbox($fake, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public int $calls = 0;

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->calls++;
                return false;
            }
        };

        $mailbox->listenForNotifications($handler, timeout: 10);

        self::assertSame(1, $handler->calls);
    }

    public function testListenToFoldersConfiguresSubscriptionAndTearsItDown(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 5 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public int $received = 0;

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->received++;
                // One push is enough — stop so teardown fires.
                return false;
            }
        };

        $mailbox->listenToFolders(
            folders: ['Archive', 'Archive/2026'],
            handler: $handler,
            timeout: 5,
        );

        self::assertSame(1, $handler->received);
        self::assertSame(
            "A0001 NOTIFY SET (mailboxes (Archive Archive/2026) (MessageNew MessageExpunge FlagChange))\r\n",
            $connection->writes[0],
        );
        self::assertSame("A0002 NOTIFY NONE\r\n", $connection->writes[1]);
    }
}
