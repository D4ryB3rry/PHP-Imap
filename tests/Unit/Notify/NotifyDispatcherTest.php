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
use D4ry\ImapClient\Notify\NotifyDispatcher;
use D4ry\ImapClient\Notify\NotifyEvent;
use D4ry\ImapClient\Notify\ServerMetadataChangeEvent;
use D4ry\ImapClient\Notify\SubscriptionChangeEvent;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\ValueObject\FlagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotifyDispatcher::class)]
#[UsesClass(NotifyEvent::class)]
#[UsesClass(MessageNewEvent::class)]
#[UsesClass(MessageExpungedEvent::class)]
#[UsesClass(FlagChangeEvent::class)]
#[UsesClass(MailboxNameEvent::class)]
#[UsesClass(SubscriptionChangeEvent::class)]
#[UsesClass(AnnotationChangeEvent::class)]
#[UsesClass(MailboxMetadataChangeEvent::class)]
#[UsesClass(ServerMetadataChangeEvent::class)]
#[UsesClass(MailboxStatusEvent::class)]
#[UsesClass(AbstractNotifyHandler::class)]
#[UsesClass(UntaggedResponse::class)]
#[UsesClass(FlagSet::class)]
final class NotifyDispatcherTest extends TestCase
{
    public function testExistsBecomesMessageNewEvent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('EXISTS', ['number' => 42, 'data' => ''], '* 42 EXISTS'));

        self::assertCount(1, $collected->events);
        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(42, $collected->events[0]->sequenceNumber);
        // Pin rawLine — kills the Coalesce mutant at NotifyDispatcher:53
        // which swaps `$untagged->raw ?? ''` to `'' ?? $untagged->raw`.
        self::assertSame('* 42 EXISTS', $collected->events[0]->rawLine);
    }

    public function testExpungeBecomesMessageExpungedEvent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('EXPUNGE', ['number' => 5, 'data' => ''], '* 5 EXPUNGE'));

        self::assertInstanceOf(MessageExpungedEvent::class, $collected->events[0]);
        self::assertSame(5, $collected->events[0]->sequenceNumber);
        self::assertSame('* 5 EXPUNGE', $collected->events[0]->rawLine);
    }

    public function testFetchWithOnlyFlagsBecomesFlagChange(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $flags = new FlagSet(['\\Seen']);
        $dispatcher->dispatch(new UntaggedResponse(
            'FETCH',
            ['seq' => 7, 'FLAGS' => $flags],
            '* 7 FETCH (FLAGS (\\Seen))',
        ));

        self::assertInstanceOf(FlagChangeEvent::class, $collected->events[0]);
        self::assertSame(7, $collected->events[0]->sequenceNumber);
        self::assertSame($flags, $collected->events[0]->flags);
        self::assertSame('* 7 FETCH (FLAGS (\\Seen))', $collected->events[0]->rawLine);
    }

    public function testFetchWithPayloadBecomesMessageNew(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $flags = new FlagSet(['\\Seen']);
        $dispatcher->dispatch(new UntaggedResponse(
            'FETCH',
            ['seq' => 9, 'UID' => 111, 'FLAGS' => $flags],
            '* 9 FETCH (UID 111 FLAGS (\\Seen))',
        ));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertArrayHasKey('UID', $collected->events[0]->fetchData);
        // Pin the FLAGS propagation — kills the Instanceof_ / Ternary
        // mutants at NotifyDispatcher:98 which would null out `$flags`
        // when a real FlagSet is present.
        self::assertSame($flags, $collected->events[0]->flags);
        self::assertSame('* 9 FETCH (UID 111 FLAGS (\\Seen))', $collected->events[0]->rawLine);
    }

    public function testFetchWithPayloadAndNonFlagSetFlagsPropagatesNullOnMessageNew(): void
    {
        // The same mutants at NotifyDispatcher:98 also escape when $flags is
        // absent, so explicitly cover the nullable branch.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'FETCH',
            ['seq' => 9, 'UID' => 111],
            '* 9 FETCH (UID 111)',
        ));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertNull($collected->events[0]->flags);
    }

    public function testStatusBecomesMailboxStatusEvent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'STATUS',
            ['mailbox' => 'INBOX', 'attributes' => ['MESSAGES' => 3, 'UIDNEXT' => 50]],
            '* STATUS INBOX (MESSAGES 3 UIDNEXT 50)',
        ));

        self::assertInstanceOf(MailboxStatusEvent::class, $collected->events[0]);
        self::assertSame('INBOX', $collected->events[0]->mailbox);
        self::assertSame(3, $collected->events[0]->attributes['MESSAGES']);
        self::assertSame('* STATUS INBOX (MESSAGES 3 UIDNEXT 50)', $collected->events[0]->rawLine);
    }

    public function testListBecomesMailboxNameEvent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'LIST',
            ['attributes' => ['\\NonExistent'], 'delimiter' => '/', 'name' => 'Old'],
            '* LIST (\\NonExistent) "/" "Old"',
        ));

        self::assertInstanceOf(MailboxNameEvent::class, $collected->events[0]);
        self::assertTrue($collected->events[0]->isNonExistent());
        self::assertSame('* LIST (\\NonExistent) "/" "Old"', $collected->events[0]->rawLine);
        // Pin the delimiter passthrough so the LogicalAndAllSubExprNegation
        // mutant at NotifyDispatcher:137 is killed — the mutant would flip
        // the guard to `!isset && !is_string`, dropping a valid string
        // delimiter and leaving the event with ''.
        self::assertSame('/', $collected->events[0]->delimiter);
        self::assertSame('Old', $collected->events[0]->mailbox);
    }

    public function testLsubBecomesSubscriptionChangeEvent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'LSUB',
            ['attributes' => ['\\Subscribed'], 'delimiter' => '/', 'name' => 'Archive'],
            '* LSUB (\\Subscribed) "/" "Archive"',
        ));

        self::assertInstanceOf(SubscriptionChangeEvent::class, $collected->events[0]);
        self::assertTrue($collected->events[0]->isSubscribed());
        self::assertSame('* LSUB (\\Subscribed) "/" "Archive"', $collected->events[0]->rawLine);
    }

    public function testMailboxMetadataUntaggedSurfacesMailboxAndRawEntries(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            '"Archive" (/private/comment "hello world" /shared/comment NIL)',
            '* METADATA "Archive" (/private/comment "hello world" /shared/comment NIL)',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Archive', $collected->events[0]->mailbox);
        self::assertSame('(/private/comment "hello world" /shared/comment NIL)', $collected->events[0]->rawEntries);
        self::assertSame(
            '* METADATA "Archive" (/private/comment "hello world" /shared/comment NIL)',
            $collected->events[0]->rawLine,
        );
    }

    public function testServerMetadataEmptyMailboxBecomesServerMetadata(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            '"" (/private/vendor "acme")',
            '* METADATA "" (/private/vendor "acme")',
        ));

        self::assertInstanceOf(ServerMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('(/private/vendor "acme")', $collected->events[0]->rawEntries);
        self::assertSame('* METADATA "" (/private/vendor "acme")', $collected->events[0]->rawLine);
    }

    public function testMetadataUntaggedWithBareAtomMailboxSplitsCorrectly(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            'Drafts (/private/comment "draft")',
            '* METADATA Drafts (/private/comment "draft")',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Drafts', $collected->events[0]->mailbox);
        self::assertSame('(/private/comment "draft")', $collected->events[0]->rawEntries);
    }

    public function testMetadataUntaggedWithBareAtomAndNoEntriesYieldsServerScope(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        // No whitespace → treated as a single bare atom mailbox name with
        // no entries. Used to make sure the `strpos === false` branch is
        // exercised.
        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            'Drafts',
            '* METADATA Drafts',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Drafts', $collected->events[0]->mailbox);
        self::assertSame('', $collected->events[0]->rawEntries);
    }

    public function testMetadataUntaggedWithEmptyPayloadYieldsServerScope(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('METADATA', '', '* METADATA'));

        self::assertInstanceOf(ServerMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('', $collected->events[0]->rawEntries);
    }

    public function testMetadataUntaggedWithNonStringDataFallsBackToServerScope(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        // The parser only emits string data for METADATA, but guard the
        // branch anyway — `is_string` failing the check.
        $dispatcher->dispatch(new UntaggedResponse('METADATA', null, '* METADATA'));

        self::assertInstanceOf(ServerMetadataChangeEvent::class, $collected->events[0]);
    }

    public function testAnnotationFetchUntaggedBecomesAnnotationChange(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'FETCH',
            ['seq' => 4, 'ANNOTATION' => [['/comment', 'note']]],
            '* 4 FETCH (ANNOTATION (...))',
        ));

        self::assertInstanceOf(AnnotationChangeEvent::class, $collected->events[0]);
        self::assertSame(4, $collected->events[0]->sequenceNumber);
        self::assertSame('* 4 FETCH (ANNOTATION (...))', $collected->events[0]->rawLine);
    }

    public function testStatusWithoutMailboxNameIsIgnored(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $result = $dispatcher->dispatch(new UntaggedResponse(
            'STATUS',
            ['mailbox' => '', 'attributes' => []],
            '* STATUS',
        ));

        self::assertTrue($result);
        self::assertSame([], $collected->events);
    }

    public function testListWithoutMailboxNameIsIgnored(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $result = $dispatcher->dispatch(new UntaggedResponse(
            'LIST',
            ['attributes' => [], 'delimiter' => '/', 'name' => ''],
            '* LIST () "/" ""',
        ));

        self::assertTrue($result);
        self::assertSame([], $collected->events);
    }

    public function testFetchWithNonArrayDataGetsEmptySequence(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('FETCH', null, '* X FETCH'));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(0, $collected->events[0]->sequenceNumber);
    }

    public function testExistsWithNonArrayDataYieldsZeroSequence(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('EXISTS', null, '* ? EXISTS'));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(0, $collected->events[0]->sequenceNumber);
    }

    public function testExistsWithNonIntegerNumberFallsBackToZero(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'EXISTS',
            ['number' => 'seven', 'data' => ''],
            '* seven EXISTS',
        ));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(0, $collected->events[0]->sequenceNumber);
    }

    public function testRawLineDefaultsToEmptyStringWhenAbsent(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        // UntaggedResponse with raw=null — the dispatcher should tolerate it.
        $dispatcher->dispatch(new UntaggedResponse(
            'EXPUNGE',
            ['number' => 2, 'data' => ''],
            null,
        ));

        self::assertSame('', $collected->events[0]->rawLine);
    }

    public function testUnrelatedUntaggedIsIgnored(): void
    {
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $result = $dispatcher->dispatch(new UntaggedResponse('OK', ['text' => 'still here', 'code' => null], '* OK still here'));

        self::assertTrue($result);
        self::assertSame([], $collected->events);
    }

    public function testHandlerReturningFalsePropagatesBreakSignal(): void
    {
        $handler = new class extends AbstractNotifyHandler {
            public function onMessageExpunged(MessageExpungedEvent $event): bool
            {
                return false;
            }
        };

        $dispatcher = new NotifyDispatcher($handler);

        $result = $dispatcher->dispatch(new UntaggedResponse('EXPUNGE', ['number' => 1, 'data' => ''], '* 1 EXPUNGE'));

        self::assertFalse($result);
    }

    public function testCallableHandlerIsSupported(): void
    {
        $seen = [];

        $dispatcher = new NotifyDispatcher(function (NotifyEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        $dispatcher->dispatch(new UntaggedResponse('EXISTS', ['number' => 11, 'data' => ''], '* 11 EXISTS'));

        self::assertCount(1, $seen);
        self::assertInstanceOf(MessageNewEvent::class, $seen[0]);
    }

    public function testCallableHandlerReturningNonFalseContinuesLoop(): void
    {
        // Kills the NotIdentical mutant at NotifyDispatcher:225
        // (`$result !== false` → `$result === false`). A non-false return
        // from a callable handler MUST be interpreted as "continue".
        $dispatcher = new NotifyDispatcher(static fn(NotifyEvent $e): bool => true);

        $result = $dispatcher->dispatch(new UntaggedResponse('EXISTS', ['number' => 1, 'data' => ''], '* 1 EXISTS'));

        self::assertTrue($result);
    }

    public function testCallableHandlerReturningFalseStopsLoop(): void
    {
        // Second half of the NotIdentical kill — a false return MUST stop.
        $dispatcher = new NotifyDispatcher(static fn(NotifyEvent $e): bool => false);

        $result = $dispatcher->dispatch(new UntaggedResponse('EXISTS', ['number' => 1, 'data' => ''], '* 1 EXISTS'));

        self::assertFalse($result);
    }

    public function testStatusMailboxOfNonStringTypeIsTreatedAsMissing(): void
    {
        // Kills the LogicalAnd mutant at NotifyDispatcher:122
        // (`isset(...) && is_string(...)` → `isset(...) || is_string(...)`).
        // With the mutant a non-string mailbox value (int) leaks straight
        // through and the event is emitted with a bogus mailbox instead of
        // being dropped.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $result = $dispatcher->dispatch(new UntaggedResponse(
            'STATUS',
            ['mailbox' => 42, 'attributes' => ['MESSAGES' => 1]],
            '* STATUS 42 (MESSAGES 1)',
        ));

        self::assertTrue($result);
        self::assertSame([], $collected->events, 'non-string mailbox must be rejected');
    }

    public function testStatusAttributesOfNonArrayTypeAreTreatedAsEmpty(): void
    {
        // Kills the LogicalAnd mutant at NotifyDispatcher:123 on the
        // `attributes` path. The mutant would forward a non-array value as
        // the attributes payload, violating the MailboxStatusEvent array
        // type contract (and changing observable behaviour).
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'STATUS',
            ['mailbox' => 'INBOX', 'attributes' => 'not-an-array'],
            '* STATUS INBOX not-an-array',
        ));

        self::assertInstanceOf(MailboxStatusEvent::class, $collected->events[0]);
        self::assertSame([], $collected->events[0]->attributes);
    }

    public function testListNameOfNonStringTypeIsTreatedAsMissing(): void
    {
        // Kills LogicalAnd at NotifyDispatcher:136 on the `name` path.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $result = $dispatcher->dispatch(new UntaggedResponse(
            'LIST',
            ['attributes' => [], 'delimiter' => '/', 'name' => 42],
            '* LIST () "/" 42',
        ));

        self::assertTrue($result);
        self::assertSame([], $collected->events);
    }

    public function testListDelimiterOfNonStringTypeFallsBackToEmpty(): void
    {
        // Kills the cluster of LogicalAnd / Ternary mutants at
        // NotifyDispatcher:137 on the `delimiter` path. A non-string
        // delimiter must be normalised to an empty string; the mutants
        // would either leak the bogus value through or swap the ternary
        // arms.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'LIST',
            ['attributes' => [], 'delimiter' => 42, 'name' => 'Archive'],
            '* LIST () 42 "Archive"',
        ));

        self::assertInstanceOf(MailboxNameEvent::class, $collected->events[0]);
        self::assertSame('', $collected->events[0]->delimiter);
    }

    public function testListAttributesOfNonArrayTypeFallBackToEmpty(): void
    {
        // Kills LogicalAnd at NotifyDispatcher:138.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'LIST',
            ['attributes' => 'nope', 'delimiter' => '/', 'name' => 'Archive'],
            '* LIST nope "/" "Archive"',
        ));

        self::assertInstanceOf(MailboxNameEvent::class, $collected->events[0]);
        self::assertSame([], $collected->events[0]->attributes);
    }

    public function testMetadataPayloadLeadingWhitespaceIsTrimmedBeforeSplit(): void
    {
        // Kills UnwrapTrim at NotifyDispatcher:159. Without the trim the
        // leading whitespace keeps the payload from matching the quoted
        // mailbox regex and the event would silently become a server-scope
        // metadata change.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            '   "Archive" (/private/comment "hello")',
            '* METADATA "Archive" (/private/comment "hello")',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Archive', $collected->events[0]->mailbox);
        self::assertSame('(/private/comment "hello")', $collected->events[0]->rawEntries);
    }

    public function testMetadataQuotedMailboxWithMultilineEntriesStillMatches(): void
    {
        // Kills PregMatchRemoveFlags at NotifyDispatcher:182 — the `s` flag
        // is required so that `.*` in the entries capture can span newlines.
        // Without `s`, `.*` stops at the first `\n`, the `$` anchor fails,
        // the whole regex falls through, and the bare-atom path would leave
        // the mailbox string including its surrounding quotes.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            "\"Archive\" (/private/a \"one\"\n/private/b \"two\")",
            '* METADATA',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Archive', $collected->events[0]->mailbox);
        self::assertSame("(/private/a \"one\"\n/private/b \"two\")", $collected->events[0]->rawEntries);
    }

    public function testMetadataQuotedMailboxEntriesTrailingWhitespaceIsTrimmed(): void
    {
        // Kills UnwrapTrim at NotifyDispatcher:183 — the entries portion
        // must be trimmed even when a quoted mailbox is in play.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            '"Archive" (/private/comment "x")   ',
            '* METADATA "Archive" (/private/comment "x")   ',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('(/private/comment "x")', $collected->events[0]->rawEntries);
    }

    public function testMetadataBareMailboxEntriesTrailingWhitespaceIsTrimmed(): void
    {
        // Kills the DecrementInteger / UnwrapTrim mutants at
        // NotifyDispatcher:191. Without `+1` the leading space would be
        // preserved (or its removal via trim the only thing catching the
        // bug); without the trim the trailing whitespace survives.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'METADATA',
            "Drafts (/private/comment \"x\")   \t ",
            '* METADATA',
        ));

        self::assertInstanceOf(MailboxMetadataChangeEvent::class, $collected->events[0]);
        self::assertSame('Drafts', $collected->events[0]->mailbox);
        self::assertSame('(/private/comment "x")', $collected->events[0]->rawEntries);
    }

    public function testExtractNumberTolerateObjectDataAndReturnsZero(): void
    {
        // Kills ReturnRemoval at NotifyDispatcher:197. Without the early
        // `return 0;` when `!is_array($untagged->data)` the code falls
        // through to `$untagged->data['number']`, which for an object
        // payload raises a PHP Error and crashes the dispatcher.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse('EXISTS', new \stdClass(), '* ? EXISTS'));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(0, $collected->events[0]->sequenceNumber);
    }

    public function testExtractNumberMissingNumberKeyDefaultsToZeroNotOne(): void
    {
        // Kills the Increment/Decrement mutants at NotifyDispatcher:200
        // on the `?? 0` coalesce default. With `?? 1` or `?? -1` the event
        // would report a phantom sequence number.
        $collected = $this->collectingHandler();
        $dispatcher = new NotifyDispatcher($collected);

        $dispatcher->dispatch(new UntaggedResponse(
            'EXISTS',
            ['data' => ''], // no 'number' key
            '* ? EXISTS',
        ));

        self::assertInstanceOf(MessageNewEvent::class, $collected->events[0]);
        self::assertSame(0, $collected->events[0]->sequenceNumber);
    }

    /**
     * @return object{events: list<NotifyEvent>}&AbstractNotifyHandler
     */
    private function collectingHandler(): AbstractNotifyHandler
    {
        return new class extends AbstractNotifyHandler {
            /** @var list<NotifyEvent> */
            public array $events = [];

            public function onMessageNew(MessageNewEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onMessageExpunged(MessageExpungedEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onFlagChange(FlagChangeEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onMailboxName(MailboxNameEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onSubscriptionChange(SubscriptionChangeEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onAnnotationChange(AnnotationChangeEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onMailboxMetadataChange(MailboxMetadataChangeEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onServerMetadataChange(ServerMetadataChangeEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }

            public function onMailboxStatus(MailboxStatusEvent $event): bool
            {
                $this->events[] = $event;
                return true;
            }
        };
    }
}
