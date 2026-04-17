<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Notify\EventGroup;
use D4ry\ImapClient\Notify\MailboxFilter;
use D4ry\ImapClient\Notify\NotifyEventType;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventGroup::class)]
#[UsesClass(MailboxFilter::class)]
#[UsesClass(NotifyEventType::class)]
#[UsesClass(CommandBuilder::class)]
final class EventGroupTest extends TestCase
{
    public function testPlainGroupEmitsFilterAndEventsParenthesised(): void
    {
        $group = new EventGroup(
            MailboxFilter::selected(),
            [NotifyEventType::MessageNew, NotifyEventType::MessageExpunge, NotifyEventType::FlagChange],
        );

        self::assertSame(
            '(selected (MessageNew MessageExpunge FlagChange))',
            $group->toGroupToken(true),
        );
    }

    public function testMessageNewWithFetchAttributesEmitsInlineList(): void
    {
        $group = new EventGroup(
            MailboxFilter::inboxes(),
            [NotifyEventType::MessageNew, NotifyEventType::MessageExpunge],
            ['UID', 'FLAGS', 'BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)]'],
        );

        self::assertSame(
            '(inboxes (MessageNew (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)]) MessageExpunge))',
            $group->toGroupToken(true),
        );
    }

    public function testFetchAttributesWithoutMessageNewAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventGroup(
            MailboxFilter::selected(),
            [NotifyEventType::FlagChange],
            ['UID'],
        );
    }

    public function testEmptyEventListRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventGroup(MailboxFilter::selected(), []);
    }

    public function testUnknownFetchAttributeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventGroup(
            MailboxFilter::inboxes(),
            [NotifyEventType::MessageNew],
            ['HACKERMANS'],
        );
    }

    public function testSubtreeFilterIsNestedCorrectlyInGroupToken(): void
    {
        $group = new EventGroup(
            MailboxFilter::subtree(['Archive', 'Archive/2026']),
            [NotifyEventType::MessageNew, NotifyEventType::MessageExpunge],
        );

        self::assertSame(
            '(subtree (Archive Archive/2026) (MessageNew MessageExpunge))',
            $group->toGroupToken(true),
        );
    }
}
