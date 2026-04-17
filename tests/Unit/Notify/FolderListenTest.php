<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Folder;
use D4ry\ImapClient\Notify\AbstractNotifyHandler;
use D4ry\ImapClient\Notify\EventGroup;
use D4ry\ImapClient\Notify\MailboxFilter;
use D4ry\ImapClient\Notify\MessageNewEvent;
use D4ry\ImapClient\Notify\NotifyDispatcher;
use D4ry\ImapClient\Notify\NotifyEvent;
use D4ry\ImapClient\Notify\NotifyEventType;
use D4ry\ImapClient\Notify\NotifyListener;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\Response\FetchResponseParser;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Folder::class)]
#[CoversClass(NotifyListener::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(NotifyDispatcher::class)]
#[UsesClass(EventGroup::class)]
#[UsesClass(MailboxFilter::class)]
#[UsesClass(NotifyEventType::class)]
#[UsesClass(NotifyEvent::class)]
#[UsesClass(AbstractNotifyHandler::class)]
#[UsesClass(MessageNewEvent::class)]
#[UsesClass(Command::class)]
#[UsesClass(CommandBuilder::class)]
#[UsesClass(Response::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(FetchResponseParser::class)]
#[UsesClass(UntaggedResponse::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
#[UsesClass(FlagSet::class)]
#[UsesClass(MailboxPath::class)]
#[UsesClass(\D4ry\ImapClient\Exception\TimeoutException::class)]
final class FolderListenTest extends TestCase
{
    public function testFolderListenDefaultsToMailboxesFilterAndTearsDown(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 42 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        $transceiver = new Transceiver($connection);
        $caps = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $caps->setValue($transceiver, [Capability::Imap4rev1, Capability::Notify]);

        $folder = new Folder(
            transceiver: $transceiver,
            mailboxPath: new MailboxPath('Archive'),
        );

        $handler = new class extends AbstractNotifyHandler {
            public int $received = 0;

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->received++;
                return false;
            }
        };

        $folder->listen($handler, timeout: 5);

        self::assertSame(1, $handler->received);
        self::assertSame(
            "A0001 NOTIFY SET (mailboxes Archive (MessageNew MessageExpunge FlagChange))\r\n",
            $connection->writes[0],
        );
        self::assertSame("A0002 NOTIFY NONE\r\n", $connection->writes[1]);
    }

    public function testFolderListenDefaultTimeoutIs300Seconds(): void
    {
        // Kills Increment / Decrement mutants on the float $timeout = 300
        // default at Folder.php:368. The default is never observable via
        // behaviour (the drain loop exits on the first handler response),
        // so the test calls listen() with its default — covering line 368
        // for Infection — and then asserts the raw default via Reflection.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 1 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        $transceiver = new Transceiver($connection);
        $caps = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $caps->setValue($transceiver, [Capability::Imap4rev1, Capability::Notify]);

        $folder = new Folder(
            transceiver: $transceiver,
            mailboxPath: new MailboxPath('Archive'),
        );

        $handler = new class extends AbstractNotifyHandler {
            public function onMessageNew(MessageNewEvent $event): bool
            {
                return false;
            }
        };

        // Invoke with the default $timeout — DO NOT pass an explicit value.
        $folder->listen($handler);

        $ref = new \ReflectionMethod(Folder::class, 'listen');
        self::assertSame(300.0, $ref->getParameters()[1]->getDefaultValue());
    }

    public function testFolderListenWithSubtreeOptionUsesSubtreeFilter(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 1 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        $transceiver = new Transceiver($connection);
        $caps = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $caps->setValue($transceiver, [Capability::Imap4rev1, Capability::Notify]);

        $folder = new Folder(
            transceiver: $transceiver,
            mailboxPath: new MailboxPath('Archive'),
        );

        $handler = new class extends AbstractNotifyHandler {
            public function onMessageNew(MessageNewEvent $event): bool
            {
                return false;
            }
        };

        $folder->listen(
            $handler,
            timeout: 5,
            events: [NotifyEventType::MessageNew],
            includeSubtree: true,
        );

        self::assertSame(
            "A0001 NOTIFY SET (subtree Archive (MessageNew))\r\n",
            $connection->writes[0],
        );
    }
}
