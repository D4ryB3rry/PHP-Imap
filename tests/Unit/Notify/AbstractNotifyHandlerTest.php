<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Notify\AbstractNotifyHandler;
use D4ry\ImapClient\Notify\AnnotationChangeEvent;
use D4ry\ImapClient\Notify\FlagChangeEvent;
use D4ry\ImapClient\Notify\MailboxMetadataChangeEvent;
use D4ry\ImapClient\Notify\MailboxNameEvent;
use D4ry\ImapClient\Notify\MailboxStatusEvent;
use D4ry\ImapClient\Notify\MessageExpungedEvent;
use D4ry\ImapClient\Notify\MessageNewEvent;
use D4ry\ImapClient\Notify\NotifyEvent;
use D4ry\ImapClient\Notify\ServerMetadataChangeEvent;
use D4ry\ImapClient\Notify\SubscriptionChangeEvent;
use D4ry\ImapClient\ValueObject\FlagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractNotifyHandler::class)]
#[CoversClass(NotifyEvent::class)]
#[CoversClass(MessageNewEvent::class)]
#[CoversClass(MessageExpungedEvent::class)]
#[CoversClass(FlagChangeEvent::class)]
#[CoversClass(MailboxNameEvent::class)]
#[CoversClass(SubscriptionChangeEvent::class)]
#[CoversClass(AnnotationChangeEvent::class)]
#[CoversClass(MailboxMetadataChangeEvent::class)]
#[CoversClass(ServerMetadataChangeEvent::class)]
#[CoversClass(MailboxStatusEvent::class)]
#[UsesClass(FlagSet::class)]
final class AbstractNotifyHandlerTest extends TestCase
{
    public function testDefaultHandlerReturnsTrueForAllEvents(): void
    {
        $handler = new class extends AbstractNotifyHandler {};

        // Every event subclass calls parent::__construct($rawLine) in its
        // own constructor to populate NotifyEvent::$rawLine. Read the prop
        // back after construction so the MethodCallRemoval mutants on each
        // parent::__construct line are killed (accessing an uninitialised
        // readonly prop raises an Error at read-time, failing the test).
        $messageNew = new MessageNewEvent('* 5 FETCH (...)', 5, []);
        self::assertSame('* 5 FETCH (...)', $messageNew->rawLine);
        self::assertTrue($handler->onMessageNew($messageNew));

        $expunged = new MessageExpungedEvent('* 3 EXPUNGE', 3);
        self::assertSame('* 3 EXPUNGE', $expunged->rawLine);
        self::assertTrue($handler->onMessageExpunged($expunged));

        $flagChange = new FlagChangeEvent('* 1 FETCH (FLAGS)', 1, new FlagSet());
        self::assertSame('* 1 FETCH (FLAGS)', $flagChange->rawLine);
        self::assertTrue($handler->onFlagChange($flagChange));

        $name = new MailboxNameEvent('* LIST () "/" A', 'A', '/', []);
        self::assertSame('* LIST () "/" A', $name->rawLine);
        self::assertTrue($handler->onMailboxName($name));

        $sub = new SubscriptionChangeEvent('* LIST () "/" A', 'A', '/', []);
        self::assertSame('* LIST () "/" A', $sub->rawLine);
        self::assertTrue($handler->onSubscriptionChange($sub));

        $annotation = new AnnotationChangeEvent('* 1 FETCH (ANNOTATION)', 1, []);
        self::assertSame('* 1 FETCH (ANNOTATION)', $annotation->rawLine);
        self::assertTrue($handler->onAnnotationChange($annotation));

        $mboxMeta = new MailboxMetadataChangeEvent('* METADATA A ()', 'A', '');
        self::assertSame('* METADATA A ()', $mboxMeta->rawLine);
        self::assertTrue($handler->onMailboxMetadataChange($mboxMeta));

        $srvMeta = new ServerMetadataChangeEvent('* METADATA ""', '');
        self::assertSame('* METADATA ""', $srvMeta->rawLine);
        self::assertTrue($handler->onServerMetadataChange($srvMeta));

        $status = new MailboxStatusEvent('* STATUS A ()', 'A', []);
        self::assertSame('* STATUS A ()', $status->rawLine);
        self::assertTrue($handler->onMailboxStatus($status));
    }

    public function testMailboxNameNonExistentFlagDetected(): void
    {
        $event = new MailboxNameEvent('* LIST (\\NonExistent) "/" "Gone"', 'Gone', '/', ['\\NonExistent']);

        self::assertTrue($event->isNonExistent());
    }

    public function testSubscriptionChangeSubscribedFlag(): void
    {
        $event = new SubscriptionChangeEvent('* LIST (\\Subscribed) "/" "A"', 'A', '/', ['\\Subscribed']);

        self::assertTrue($event->isSubscribed());
    }
}
