<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\CapabilityException;
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
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(NotifyListener::class)]
#[UsesClass(NotifyDispatcher::class)]
#[UsesClass(EventGroup::class)]
#[UsesClass(MailboxFilter::class)]
#[UsesClass(NotifyEventType::class)]
#[UsesClass(NotifyEvent::class)]
#[UsesClass(AbstractNotifyHandler::class)]
#[UsesClass(MessageNewEvent::class)]
#[UsesClass(Transceiver::class)]
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
final class NotifyListenerTest extends TestCase
{
    private function transceiverWithCapabilities(FakeConnection $connection, Capability ...$caps): Transceiver
    {
        $transceiver = new Transceiver($connection);
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, [Capability::Imap4rev1, ...$caps]);
        return $transceiver;
    }

    public function testDrainSkipsBlankAndNonUntaggedLines(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '',                        // empty line — skip
            'garbage without prefix',  // not starting with "* " — skip
            '* 1 EXISTS',              // dispatch
        );

        $transceiver = $this->transceiverWithCapabilities($connection, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public int $seen = 0;
            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->seen = $event->sequenceNumber;
                return false;
            }
        };

        NotifyListener::drain($transceiver, $handler, timeout: 5);

        self::assertSame(1, $handler->seen);
    }

    public function testDrainRequiresNotifyCapability(): void
    {
        $connection = new FakeConnection();
        $transceiver = $this->transceiverWithCapabilities($connection); // no Notify

        $this->expectException(CapabilityException::class);

        NotifyListener::drain(
            $transceiver,
            static fn() => true,
            timeout: 1,
        );
    }

    public function testListenToMailboxesRejectsEmptyMailboxList(): void
    {
        $connection = new FakeConnection();
        $transceiver = $this->transceiverWithCapabilities($connection, Capability::Notify);

        $this->expectException(\InvalidArgumentException::class);

        NotifyListener::listenToMailboxes(
            $transceiver,
            [],
            static fn() => true,
            timeout: 1,
        );
    }

    public function testListenToMailboxesCustomEventsFlowThroughToWire(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK NOTIFY completed',
            '* 1 EXISTS',
            'A0002 OK NOTIFY NONE completed',
        );

        $transceiver = $this->transceiverWithCapabilities($connection, Capability::Notify);

        $handler = new class extends AbstractNotifyHandler {
            public function onMessageNew(MessageNewEvent $event): bool
            {
                return false;
            }
        };

        NotifyListener::listenToMailboxes(
            $transceiver,
            ['Inbox'],
            $handler,
            timeout: 5,
            events: [NotifyEventType::MessageNew],
            includeSubtree: true,
        );

        self::assertSame(
            "A0001 NOTIFY SET (subtree Inbox (MessageNew))\r\n",
            $connection->writes[0],
        );
    }
}
