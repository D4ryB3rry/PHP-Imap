<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Idle;

use D4ry\ImapClient\Idle\AbstractIdleHandler;
use D4ry\ImapClient\Idle\FlagsChangedEvent;
use D4ry\ImapClient\Idle\IdleHeartbeatEvent;
use D4ry\ImapClient\Idle\MessageExpungedEvent;
use D4ry\ImapClient\Idle\MessageReceivedEvent;
use D4ry\ImapClient\Idle\RecentCountEvent;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\Idle\IdleEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractIdleHandler::class)]
#[CoversClass(IdleEvent::class)]
#[CoversClass(MessageReceivedEvent::class)]
#[CoversClass(MessageExpungedEvent::class)]
#[CoversClass(FlagsChangedEvent::class)]
#[CoversClass(RecentCountEvent::class)]
#[CoversClass(IdleHeartbeatEvent::class)]
#[UsesClass(FlagSet::class)]
final class AbstractIdleHandlerTest extends TestCase
{
    public function testDefaultHandlerReturnsTrueForAllEvents(): void
    {
        $handler = new class extends AbstractIdleHandler {};

        self::assertTrue($handler->onMessageReceived(new MessageReceivedEvent('* 5 EXISTS', 5)));
        self::assertTrue($handler->onMessageExpunged(new MessageExpungedEvent('* 3 EXPUNGE', 3)));
        self::assertTrue($handler->onFlagsChanged(new FlagsChangedEvent('* 1 FETCH', 1, new FlagSet())));
        self::assertTrue($handler->onRecentCount(new RecentCountEvent('* 2 RECENT', 2)));
        self::assertTrue($handler->onHeartbeat(new IdleHeartbeatEvent('* OK ping', 'OK ping')));
    }

    public function testSubclassCanOverrideAndReturnFalseToStop(): void
    {
        $handler = new class extends AbstractIdleHandler {
            public int $messagesReceived = 0;

            public function onMessageReceived(MessageReceivedEvent $event): bool
            {
                $this->messagesReceived++;
                return false;
            }
        };

        self::assertFalse($handler->onMessageReceived(new MessageReceivedEvent('* 5 EXISTS', 5)));
        self::assertSame(1, $handler->messagesReceived);
    }

    public function testMessageReceivedSequenceNumberMatchesCount(): void
    {
        $event = new MessageReceivedEvent('* 7 EXISTS', 7);

        self::assertSame(7, $event->messageCount);
        self::assertSame(7, $event->sequenceNumber);
    }
}
