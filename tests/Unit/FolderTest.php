<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Collection\MessageCollection;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\ImapException;
use D4ry\ImapClient\Folder;
use D4ry\ImapClient\Message;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\MailboxStatus;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(Folder::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(MessageCollection::class)]
#[UsesClass(FolderCollection::class)]
#[UsesClass(MailboxPath::class)]
#[UsesClass(MailboxStatus::class)]
#[UsesClass(Uid::class)]
#[UsesClass(SearchResult::class)]
#[UsesClass(Search::class)]
#[UsesClass(Message::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\Command::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\CommandBuilder::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\Response::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\ResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\FetchResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\UntaggedResponse::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\StreamingFetchState::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\TagGenerator::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Tag::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Envelope::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\FlagSet::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\SequenceNumber::class)]
#[UsesClass(\D4ry\ImapClient\Mime\HeaderDecoder::class)]
#[UsesClass(\D4ry\ImapClient\Support\ImapDateFormatter::class)]
#[UsesClass(\D4ry\ImapClient\Exception\ParseException::class)]
#[UsesClass(\D4ry\ImapClient\Exception\ImapException::class)]
#[UsesClass(\D4ry\ImapClient\Exception\CommandException::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\BodyStructure::class)]
final class FolderTest extends TestCase
{
    private function setCapabilities(Transceiver $transceiver, Capability ...$caps): void
    {
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, $caps);
    }

    private function makeFolder(
        FakeConnection $connection,
        string $path = 'INBOX',
        ?SpecialUse $specialUse = null,
        array $caps = [],
    ): array {
        $transceiver = new Transceiver($connection);
        // Always seed with at least Imap4rev1 so the cache is non-empty and
        // Transceiver does not issue an unscripted CAPABILITY roundtrip.
        $this->setCapabilities($transceiver, Capability::Imap4rev1, ...$caps);
        $folder = new Folder($transceiver, new MailboxPath($path), $specialUse, []);

        return [$folder, $transceiver];
    }

    public function testAccessors(): void
    {
        $connection = new FakeConnection();
        [$folder] = $this->makeFolder($connection, 'INBOX/Drafts', SpecialUse::Drafts);

        self::assertSame('INBOX/Drafts', $folder->path()->path);
        self::assertSame('Drafts', $folder->name());
        self::assertSame(SpecialUse::Drafts, $folder->specialUse());
    }

    public function testStatusFetchesAndCachesResult(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS INBOX (MESSAGES 12 RECENT 1 UIDNEXT 13 UIDVALIDITY 999 UNSEEN 3)',
            'A0001 OK STATUS completed',
        );

        [$folder] = $this->makeFolder($connection);

        $status = $folder->status();

        self::assertSame(12, $status->messages);
        self::assertSame(1, $status->recent);
        self::assertSame(13, $status->uidNext);
        self::assertSame(999, $status->uidValidity);
        self::assertSame(3, $status->unseen);
        self::assertNull($status->highestModSeq);
        self::assertNull($status->size);

        // Second call must hit the cache — no new write.
        $writeCountBefore = count($connection->writes);
        $folder->status();
        self::assertCount($writeCountBefore, $connection->writes);

        self::assertSame(
            'A0001 STATUS INBOX (MESSAGES RECENT UIDNEXT UIDVALIDITY UNSEEN)' . "\r\n",
            $connection->writes[0],
        );
    }

    public function testStatusIncludesCondstoreAndStatusSizeAttributes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS INBOX (MESSAGES 5 RECENT 0 UIDNEXT 6 UIDVALIDITY 1 UNSEEN 0 HIGHESTMODSEQ 42 SIZE 1024)',
            'A0001 OK STATUS done',
        );

        [$folder] = $this->makeFolder($connection, 'INBOX', null, [Capability::Condstore, Capability::StatusSize]);

        $status = $folder->status();

        self::assertSame(42, $status->highestModSeq);
        self::assertSame(1024, $status->size);
        self::assertStringContainsString('HIGHESTMODSEQ', $connection->writes[0]);
        self::assertStringContainsString('SIZE', $connection->writes[0]);
    }

    public function testSelectIsCachedWhenAlreadySelected(): void
    {
        $connection = new FakeConnection();
        [$folder, $transceiver] = $this->makeFolder($connection);
        $transceiver->selectedMailbox = 'INBOX';

        $folder->select();

        self::assertSame([], $connection->writes);
    }

    public function testSelectSendsSelectAndUpdatesState(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK [READ-WRITE] SELECT done');

        [$folder, $transceiver] = $this->makeFolder($connection);

        $folder->select();

        self::assertSame("A0001 SELECT INBOX\r\n", $connection->writes[0]);
        self::assertSame('INBOX', $transceiver->selectedMailbox);
    }

    public function testExamineSendsExamineAndUpdatesState(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK [READ-ONLY] EXAMINE done');

        [$folder, $transceiver] = $this->makeFolder($connection);

        $folder->examine();

        self::assertSame("A0001 EXAMINE INBOX\r\n", $connection->writes[0]);
        self::assertSame('INBOX', $transceiver->selectedMailbox);
    }

    public function testCreateSendsCreate(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK CREATE done');

        [$folder] = $this->makeFolder($connection, 'Archive');

        $result = $folder->create();

        self::assertSame("A0001 CREATE Archive\r\n", $connection->writes[0]);
        self::assertSame($folder, $result);
    }

    public function testDeleteWithoutSelectionOnlyDeletes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK DELETE done');

        [$folder] = $this->makeFolder($connection, 'OldStuff');

        $folder->delete();

        self::assertCount(1, $connection->writes);
        self::assertSame("A0001 DELETE OldStuff\r\n", $connection->writes[0]);
    }

    public function testDeleteWhenSelectedWithUnselectCapability(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK UNSELECT done',
            'A0002 OK DELETE done',
        );

        [$folder, $transceiver] = $this->makeFolder($connection, 'OldStuff', null, [Capability::Unselect]);
        $transceiver->selectedMailbox = 'OldStuff';

        $folder->delete();

        self::assertSame("A0001 UNSELECT\r\n", $connection->writes[0]);
        self::assertSame("A0002 DELETE OldStuff\r\n", $connection->writes[1]);
        self::assertNull($transceiver->selectedMailbox);
    }

    public function testDeleteWhenSelectedWithoutUnselectCapability(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK CLOSE done',
            'A0002 OK DELETE done',
        );

        [$folder, $transceiver] = $this->makeFolder($connection, 'OldStuff');
        $transceiver->selectedMailbox = 'OldStuff';

        $folder->delete();

        self::assertSame("A0001 CLOSE\r\n", $connection->writes[0]);
        self::assertSame("A0002 DELETE OldStuff\r\n", $connection->writes[1]);
    }

    public function testRenameUpdatesPath(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK RENAME done');

        [$folder] = $this->makeFolder($connection, 'INBOX/Old');

        $folder->rename('New');

        self::assertSame("A0001 RENAME INBOX/Old INBOX/New\r\n", $connection->writes[0]);
        self::assertSame('INBOX/New', $folder->path()->path);
    }

    public function testSubscribeAndUnsubscribe(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SUBSCRIBE done',
            'A0002 OK UNSUBSCRIBE done',
        );

        [$folder] = $this->makeFolder($connection);

        $folder->subscribe();
        $folder->unsubscribe();

        self::assertSame("A0001 SUBSCRIBE INBOX\r\n", $connection->writes[0]);
        self::assertSame("A0002 UNSUBSCRIBE INBOX\r\n", $connection->writes[1]);
    }

    public function testExpungeSelectsAndExpunges(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            'A0002 OK EXPUNGE done',
        );

        [$folder] = $this->makeFolder($connection);

        $folder->expunge();

        self::assertSame("A0001 SELECT INBOX\r\n", $connection->writes[0]);
        self::assertSame("A0002 EXPUNGE\r\n", $connection->writes[1]);
    }

    public function testChildrenIsLazyAndParsesListResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasNoChildren) "/" "INBOX/Drafts"',
            '* LIST (\HasNoChildren \Sent) "/" "INBOX/Sent"',
            'A0001 OK LIST done',
        );

        [$folder] = $this->makeFolder($connection);

        $children = $folder->children();
        self::assertSame([], $connection->writes, 'children() must be lazy');

        self::assertSame(2, $children->count());
        self::assertSame("A0001 LIST \"\" INBOX/%\r\n", $connection->writes[0]);
        self::assertSame(SpecialUse::Sent, $children->byName('Sent')?->specialUse());
    }

    public function testMessagesWithoutCriteriaSelectsAndFetchesSequenceRange(): void
    {
        // The unfiltered fast path skips UID SEARCH entirely and FETCHes the
        // whole mailbox by sequence range — saves a roundtrip and avoids
        // shipping a potentially huge UID list back and forth.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* 1 FETCH (UID 100 FLAGS (\Seen) INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1234 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 200 FLAGS () INTERNALDATE "02-Jan-2024 12:00:00 +0000" RFC822.SIZE 5678 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $messages = $folder->messages();
        self::assertSame(2, $messages->count());
        self::assertSame(100, $messages[0]->uid()->value);
        self::assertSame(200, $messages[1]->uid()->value);
        self::assertStringStartsWith('A0001 SELECT', $connection->writes[0]);
        self::assertStringStartsWith('A0002 FETCH 1:*', $connection->writes[1]);
        self::assertStringNotContainsString('SEARCH', $connection->writes[1]);
    }

    public function testMessagesWithFlagCriteria(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH',
            'A0002 OK SEARCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $result = $folder->messages(Flag::Seen);
        self::assertSame(0, $result->count());

        self::assertSame("A0002 UID SEARCH SEEN\r\n", $connection->writes[1]);
    }

    public function testMessagesWithSearchCriteria(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH',
            'A0002 OK SEARCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $criteria = (new Search())->unread()->subject('hello');
        $result = $folder->messages($criteria);
        self::assertTrue($result->isEmpty());

        self::assertSame("A0002 UID SEARCH UNSEEN SUBJECT \"hello\"\r\n", $connection->writes[1]);
    }

    public function testMessagesWithCriteriaCompressesUidRangesAndStreams(): void
    {
        // Server returns 6 UIDs that compress to "1:3,7,9:10" — verifies both
        // the criteria-with-results streaming branch and that contiguous-range
        // compression actually shows up on the wire.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH 1 2 3 7 9 10',
            'A0002 OK SEARCH done',
            '* 1 FETCH (UID 1 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 2 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 3 FETCH (UID 3 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 4 FETCH (UID 7 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 5 FETCH (UID 9 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 6 FETCH (UID 10 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0003 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $messages = $folder->messages(Flag::Seen);
        $iterated = [];
        foreach ($messages as $m) {
            $iterated[] = $m->uid()->value;
        }

        self::assertSame([1, 2, 3, 7, 9, 10], $iterated);
        self::assertStringContainsString('UID FETCH 1:3,7,9:10', $connection->writes[2]);
    }

    public function testNestedCommandInsideStreamingFetchDoesNotDeadlock(): void
    {
        // Regression: previously, calling $msg->bodyStructure() (or any other
        // command-issuing method) on a message yielded by messagesRange()
        // would let the inner command consume the outer FETCH's remaining
        // untagged + tagged responses. The outer streaming generator then
        // blocked on a socket read for data that would never arrive.
        //
        // Worst-case scenario: trigger the nested command on the *first*
        // yielded message — this forces the drain path to buffer the entire
        // remainder of the outer FETCH before the inner command goes out.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 1 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 2 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 3 FETCH (UID 3 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0001 OK FETCH done',
            // Nested BODYSTRUCTURE fetch triggered from inside the foreach.
            '* 1 FETCH (UID 1 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL))',
            'A0002 OK FETCH done',
        );

        [$folder, $transceiver] = $this->makeFolder($connection);
        // Pre-mark INBOX selected so messagesRange()->select() is a no-op
        // and the test only has to script the FETCH responses.
        $transceiver->selectedMailbox = 'INBOX';

        $iterated = [];
        $structureType = null;
        foreach ($folder->messagesRange(1, 3) as $msg) {
            $iterated[] = $msg->uid()->value;
            // Trigger the nested command on the FIRST yielded message.
            if ($structureType === null) {
                $structureType = $msg->bodyStructure()->type;
            }
        }

        self::assertSame([1, 2, 3], $iterated);
        self::assertSame('TEXT', $structureType);
        // The nested UID FETCH must have actually been issued on the wire,
        // proving the drain path didn't accidentally swallow it.
        self::assertSame("A0001 FETCH 1:3 (UID FLAGS ENVELOPE INTERNALDATE RFC822.SIZE)\r\n", $connection->writes[0]);
        self::assertSame("A0002 UID FETCH 1 (BODYSTRUCTURE)\r\n", $connection->writes[1]);
    }

    public function testEarlyBreakInsideStreamingFetchLeavesTransceiverClean(): void
    {
        // If a consumer breaks out of the foreach mid-stream, the rest of the
        // outer FETCH must still be drained so the next command starts on a
        // clean socket — otherwise the next command's reader would parse the
        // leftover untagged FETCH responses as if they belonged to it.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 1 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 2 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 3 FETCH (UID 3 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0001 OK FETCH done',
            // Follow-up command after the broken loop — must succeed.
            'A0002 OK NOOP done',
        );

        [$folder, $transceiver] = $this->makeFolder($connection);
        $transceiver->selectedMailbox = 'INBOX';

        $iterated = [];
        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($folder->messagesRange(1, 3) as $msg) {
            $iterated[] = $msg->uid()->value;
            break; // abandoned mid-stream
        }

        self::assertSame([1], $iterated);

        // Issuing a follow-up command should not blow up — drain has happened.
        $response = $transceiver->command('NOOP');
        self::assertSame(ResponseStatus::Ok, $response->status);
        self::assertSame("A0002 NOOP\r\n", $connection->writes[1]);
    }

    /**
     * @return iterable<string, array{Uid[], string}>
     */
    public static function uidCompressionProvider(): iterable
    {
        $u = static fn(int $v) => new Uid($v);

        yield 'single uid' => [[$u(42)], '42'];
        yield 'all contiguous' => [[$u(1), $u(2), $u(3), $u(4)], '1:4'];
        yield 'all isolated' => [[$u(1), $u(3), $u(5)], '1,3,5'];
        yield 'mixed' => [[$u(1), $u(2), $u(3), $u(7), $u(9), $u(10)], '1:3,7,9:10'];
        yield 'unsorted input' => [[$u(10), $u(2), $u(1), $u(3)], '1:3,10'];
        yield 'duplicates dropped' => [[$u(1), $u(1), $u(2), $u(2), $u(5)], '1:2,5'];
    }

    /**
     * @param Uid[] $uids
     */
    #[DataProvider('uidCompressionProvider')]
    public function testCompressUidsToSet(array $uids, string $expected): void
    {
        $connection = new FakeConnection();
        [$folder] = $this->makeFolder($connection);

        $method = new ReflectionMethod(Folder::class, 'compressUidsToSet');

        self::assertSame($expected, $method->invoke($folder, $uids));
    }

    public function testMessageReturnsSingleResult(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* 1 FETCH (UID 42 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 100 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $message = $folder->message(new Uid(42));
        self::assertSame(42, $message->uid()->value);
    }

    public function testMessageThrowsWhenNotFound(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $this->expectException(ImapException::class);
        $folder->message(new Uid(42));
    }

    public function testSearchReturnsResultWithUids(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH 1 2 3',
            'A0002 OK SEARCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $result = $folder->search((new Search())->all());

        self::assertSame(3, $result->count());
        self::assertSame([1, 2, 3], array_map(fn(Uid $u) => $u->value, $result->uids));
    }

    public function testAppendReturnsUidFromAppendUidResponseCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ Ready for literal data',
            'A0001 OK [APPENDUID 1 42] APPEND completed',
        );

        [$folder] = $this->makeFolder($connection);

        $uid = $folder->append("Subject: hi\r\n\r\nBody");

        self::assertNotNull($uid);
        self::assertSame(42, $uid->value);

        // First write: APPEND command line; second: literal payload + CRLF.
        self::assertCount(2, $connection->writes);
        self::assertStringStartsWith('A0001 APPEND INBOX {', $connection->writes[0]);
        self::assertStringEndsWith("}\r\n", $connection->writes[0]);
        self::assertStringEndsWith("\r\n", $connection->writes[1]);
    }

    public function testAppendWithFlagsAndDate(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ Ready',
            'A0001 OK APPEND completed',
        );

        [$folder] = $this->makeFolder($connection);

        $uid = $folder->append(
            'X',
            [Flag::Seen, '\Flagged'],
            new \DateTimeImmutable('2024-01-01 12:00:00 +0000'),
        );

        self::assertNull($uid);
        self::assertStringContainsString('(\Seen \Flagged)', $connection->writes[0]);
        self::assertStringContainsString('"01-Jan-2024', $connection->writes[0]);
    }

    public function testAppendReturnsNullWhenContinuationNotReceived(): void
    {
        $connection = new FakeConnection();
        // Server skips the continuation entirely and replies with a tagged
        // status. readResponseForTag() returns the tagged response so the
        // continuation tag is 'A0001', not '+', forcing the late return null.
        $connection->queueLines('A0001 NO append refused');

        [$folder] = $this->makeFolder($connection);

        $uid = $folder->append('X');

        self::assertNull($uid);
        self::assertCount(1, $connection->writes);
    }

    public function testFetchMessagesEarlyReturnsForEmptyUidList(): void
    {
        $connection = new FakeConnection();
        [$folder] = $this->makeFolder($connection);

        $method = new ReflectionMethod(Folder::class, 'fetchMessages');
        $result = $method->invoke($folder, []);

        self::assertSame([], $result);
        self::assertSame([], $connection->writes, 'empty UID list must short-circuit before any I/O');
    }

    public function testFetchMessagesSkipsNonFetchAndUidlessUntaggedEntries(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            // First untagged is not a FETCH (covers the type !== FETCH continue).
            '* OK Some informational line',
            // Second untagged is a FETCH but carries no UID key (covers the
            // !($uid instanceof Uid) continue).
            '* 1 FETCH (FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $messages = $folder->messages();

        self::assertSame(0, $messages->count());
    }

    public function testFetchMessagesFallsBackToNowOnUnparseableInternalDate(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* 1 FETCH (UID 9 FLAGS () INTERNALDATE "garbage" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $messages = $folder->messages();

        self::assertSame(1, $messages->count());
        // The catch swallowed the ParseException and the date stayed at "now"
        // (set just before the try). We only assert the message materialized.
        self::assertSame(9, $messages[0]->uid()->value);
    }

    /**
     * @return iterable<string, array{Flag, string}>
     */
    public static function flagSearchProvider(): iterable
    {
        yield 'answered' => [Flag::Answered, 'ANSWERED'];
        yield 'flagged' => [Flag::Flagged, 'FLAGGED'];
        yield 'deleted' => [Flag::Deleted, 'DELETED'];
        yield 'draft' => [Flag::Draft, 'DRAFT'];
        yield 'recent' => [Flag::Recent, 'RECENT'];
    }

    #[DataProvider('flagSearchProvider')]
    public function testMessagesFlagCriteriaCoversAllArms(Flag $flag, string $expectedToken): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH',
            'A0002 OK SEARCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $folder->messages($flag)->count();

        self::assertSame("A0002 UID SEARCH {$expectedToken}\r\n", $connection->writes[1]);
    }

    public function testParseFolderListSkipsLoosePayloads(): void
    {
        $connection = new FakeConnection();
        [$folder] = $this->makeFolder($connection);

        $untagged = [
            // data is a string, not an array → continue (line 390 / first guard)
            new UntaggedResponse('LIST', 'not-an-array'),
            // rawName is empty → continue (line 399)
            new UntaggedResponse('LIST', ['attributes' => [], 'delimiter' => '/', 'name' => '']),
        ];

        $method = new ReflectionMethod(Folder::class, 'parseFolderList');
        $result = $method->invoke($folder, $untagged);

        self::assertSame([], $result);
    }

    public function testFetchMessagesIncludesObjectIdItemsWhenCapable(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* 1 FETCH (UID 7 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) MODSEQ (99) EMAILID (M0001) THREADID (T0001))',
            'A0002 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection, 'INBOX', null, [Capability::Condstore, Capability::ObjectId]);

        $messages = $folder->messages();
        self::assertSame(1, $messages->count());

        $msg = $messages[0];
        self::assertSame('M0001', $msg->emailId());
        self::assertSame('T0001', $msg->threadId());
        self::assertSame(99, $msg->modSeq());

        self::assertStringContainsString('MODSEQ', $connection->writes[1]);
        self::assertStringContainsString('EMAILID', $connection->writes[1]);
        self::assertStringContainsString('THREADID', $connection->writes[1]);
    }

    public function testFetchMessagesFallsBackWhenServerRejectsObjectIdItems(): void
    {
        // Reproduces a Dovecot quirk: server advertises OBJECTID in CAPABILITY
        // but rejects EMAILID/THREADID inside FETCH with a BAD response.
        // The Folder must catch that, suppress the items for the rest of the
        // connection, and retry once.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            // First FETCH attempt: server rejects EMAILID.
            'A0002 BAD Error in IMAP command FETCH: Unknown parameter: EMAILID',
            // Retry without EMAILID/THREADID: server accepts.
            '* 1 FETCH (UID 7 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0003 OK FETCH done',
        );

        [$folder, $transceiver] = $this->makeFolder(
            $connection,
            'INBOX',
            null,
            [Capability::ObjectId],
        );

        $messages = $folder->messages();
        self::assertSame(1, $messages->count());
        self::assertSame(7, $messages[0]->uid()->value);

        // First attempt did include the OBJECTID items.
        self::assertStringContainsString('EMAILID', $connection->writes[1]);
        self::assertStringContainsString('THREADID', $connection->writes[1]);

        // Retry stripped them.
        self::assertStringNotContainsString('EMAILID', $connection->writes[2]);
        self::assertStringNotContainsString('THREADID', $connection->writes[2]);
        self::assertStringContainsString('FETCH 1:*', $connection->writes[2]);

        // Suppression flag is now set on the transceiver.
        self::assertTrue($transceiver->objectIdFetchItemsDisabled);
    }

    public function testFetchMessagesRethrowsUnrelatedBadResponses(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            'A0002 BAD Mailbox is in inconsistent state',
        );

        [$folder] = $this->makeFolder(
            $connection,
            'INBOX',
            null,
            [Capability::ObjectId],
        );

        $this->expectException(\D4ry\ImapClient\Exception\CommandException::class);
        $folder->messages()->count();
    }

    public function testMessagesRangeRejectsZeroOrigin(): void
    {
        [$folder] = $this->makeFolder(new FakeConnection());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message range: 0:5');

        $folder->messagesRange(0, 5);
    }

    public function testMessagesRangeRejectsInvertedRange(): void
    {
        [$folder] = $this->makeFolder(new FakeConnection());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message range: 5:3');

        $folder->messagesRange(5, 3);
    }
}
