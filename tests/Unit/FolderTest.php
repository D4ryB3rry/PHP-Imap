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
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\MailboxStatus;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
#[UsesClass(\D4ry\ImapClient\Protocol\TagGenerator::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Tag::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Envelope::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\FlagSet::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\SequenceNumber::class)]
#[UsesClass(\D4ry\ImapClient\Mime\HeaderDecoder::class)]
#[UsesClass(\D4ry\ImapClient\Support\ImapDateFormatter::class)]
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

    public function testMessagesWithoutCriteriaSelectsSearchesAndFetches(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH 100 200',
            'A0002 OK SEARCH done',
            '* 1 FETCH (UID 100 FLAGS (\Seen) INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1234 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            '* 2 FETCH (UID 200 FLAGS () INTERNALDATE "02-Jan-2024 12:00:00 +0000" RFC822.SIZE 5678 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL))',
            'A0003 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection);

        $messages = $folder->messages();
        self::assertSame(2, $messages->count());
        self::assertSame(100, $messages[0]->uid()->value);
        self::assertSame(200, $messages[1]->uid()->value);
        self::assertSame("A0002 UID SEARCH ALL\r\n", $connection->writes[1]);
        self::assertStringContainsString('UID FETCH 100,200', $connection->writes[2]);
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

    public function testFetchMessagesIncludesObjectIdItemsWhenCapable(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            '* SEARCH 7',
            'A0002 OK SEARCH done',
            '* 1 FETCH (UID 7 FLAGS () INTERNALDATE "01-Jan-2024 12:00:00 +0000" RFC822.SIZE 1 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) MODSEQ (99) EMAILID (M0001) THREADID (T0001))',
            'A0003 OK FETCH done',
        );

        [$folder] = $this->makeFolder($connection, 'INBOX', null, [Capability::Condstore, Capability::ObjectId]);

        $messages = $folder->messages();
        self::assertSame(1, $messages->count());

        $msg = $messages[0];
        self::assertSame('M0001', $msg->emailId());
        self::assertSame('T0001', $msg->threadId());
        self::assertSame(99, $msg->modSeq());

        self::assertStringContainsString('MODSEQ', $connection->writes[2]);
        self::assertStringContainsString('EMAILID', $connection->writes[2]);
        self::assertStringContainsString('THREADID', $connection->writes[2]);
    }
}
