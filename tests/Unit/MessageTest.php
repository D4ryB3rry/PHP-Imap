<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\Message;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\Address;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\SequenceNumber;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Message::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(\D4ry\ImapClient\Attachment::class)]
#[UsesClass(AttachmentCollection::class)]
#[UsesClass(MailboxPath::class)]
#[UsesClass(Uid::class)]
#[UsesClass(SequenceNumber::class)]
#[UsesClass(FlagSet::class)]
#[UsesClass(Envelope::class)]
#[UsesClass(Address::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\BodyStructure::class)]
#[UsesClass(\D4ry\ImapClient\Mime\MimeParser::class)]
#[UsesClass(\D4ry\ImapClient\Mime\HeaderDecoder::class)]
#[UsesClass(\D4ry\ImapClient\Mime\ParsedMessage::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\Command::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\CommandBuilder::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\Response::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\ResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\FetchResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\UntaggedResponse::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\TagGenerator::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Tag::class)]
final class MessageTest extends TestCase
{
    private function setCapabilities(Transceiver $transceiver, Capability ...$caps): void
    {
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, $caps);
    }

    private function makeMessage(
        FakeConnection $connection,
        int $uid = 42,
        FlagSet $flags = new FlagSet(),
        string $folderPath = 'INBOX',
        bool $preselect = true,
        array $caps = [],
    ): array {
        $transceiver = new Transceiver($connection);
        $this->setCapabilities($transceiver, Capability::Imap4rev1, ...$caps);

        if ($preselect) {
            $transceiver->selectedMailbox = $folderPath;
        }

        $message = new Message(
            transceiver: $transceiver,
            uid: new Uid($uid),
            sequenceNumber: new SequenceNumber(1),
            envelope: new Envelope(null, 'subject', [], [], [], [], [], [], null, null),
            flags: $flags,
            internalDate: new \DateTimeImmutable('2024-01-01 12:00:00 +0000'),
            size: 1234,
            folderPath: $folderPath,
            emailIdValue: 'M01',
            threadIdValue: 'T01',
            modSeqValue: 7,
        );

        return [$message, $transceiver];
    }

    public function testAccessors(): void
    {
        $connection = new FakeConnection();
        [$message] = $this->makeMessage($connection, 42, new FlagSet([Flag::Seen->value]));

        self::assertSame(42, $message->uid()->value);
        self::assertSame(1, $message->sequenceNumber()->value);
        self::assertSame('subject', $message->envelope()->subject);
        self::assertTrue($message->flags()->has(Flag::Seen));
        self::assertSame('2024-01-01T12:00:00+00:00', $message->internalDate()->format('c'));
        self::assertSame(1234, $message->size());
        self::assertSame('M01', $message->emailId());
        self::assertSame('T01', $message->threadId());
        self::assertSame(7, $message->modSeq());
    }

    public function testEnsureSelectedTriggersSelectWhenFolderNotSelected(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK SELECT done',
            'A0002 OK STORE done',
        );

        [$message] = $this->makeMessage($connection, 42, new FlagSet(), 'INBOX', preselect: false);

        $message->setFlag(Flag::Seen);

        self::assertSame("A0001 SELECT INBOX\r\n", $connection->writes[0]);
        self::assertStringStartsWith('A0002 UID STORE 42 +FLAGS', $connection->writes[1]);
    }

    public function testEnsureSelectedSkipsSelectWhenAlreadySelected(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK STORE done');

        [$message] = $this->makeMessage($connection);

        $message->setFlag(Flag::Seen);

        self::assertCount(1, $connection->writes);
        self::assertStringStartsWith('A0001 UID STORE', $connection->writes[0]);
    }

    public function testSetFlagAddsLocallyAndStoresOnServer(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK STORE done');

        [$message] = $this->makeMessage($connection);

        $message->setFlag(Flag::Seen, Flag::Flagged);

        self::assertSame(
            "A0001 UID STORE 42 +FLAGS (\\Seen \\Flagged)\r\n",
            $connection->writes[0],
        );
        self::assertTrue($message->flags()->has(Flag::Seen));
        self::assertTrue($message->flags()->has(Flag::Flagged));
    }

    public function testClearFlagRemovesLocallyAndOnServer(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK STORE done');

        [$message] = $this->makeMessage($connection, 42, new FlagSet([Flag::Seen->value]));

        $message->clearFlag(Flag::Seen);

        self::assertSame(
            "A0001 UID STORE 42 -FLAGS (\\Seen)\r\n",
            $connection->writes[0],
        );
        self::assertFalse($message->flags()->has(Flag::Seen));
    }

    public function testDeleteMarksWithDeletedFlag(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK STORE done');

        [$message] = $this->makeMessage($connection);

        $message->delete();

        self::assertSame(
            "A0001 UID STORE 42 +FLAGS (\\Deleted)\r\n",
            $connection->writes[0],
        );
        self::assertTrue($message->flags()->has(Flag::Deleted));
    }

    public function testCopyToWithStringPath(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK COPY done');

        [$message] = $this->makeMessage($connection);

        $message->copyTo('Archive');

        self::assertSame("A0001 UID COPY 42 Archive\r\n", $connection->writes[0]);
    }

    public function testCopyToWithFolderInterface(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK COPY done');

        [$message] = $this->makeMessage($connection);

        $folder = $this->createStub(FolderInterface::class);
        $folder->method('path')->willReturn(new MailboxPath('Archive'));

        $message->copyTo($folder);

        self::assertSame("A0001 UID COPY 42 Archive\r\n", $connection->writes[0]);
    }

    public function testMoveToUsesUidMoveWhenCapable(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK MOVE done');

        [$message] = $this->makeMessage($connection, caps: [Capability::Move]);

        $message->moveTo('Archive');

        self::assertCount(1, $connection->writes);
        self::assertSame("A0001 UID MOVE 42 Archive\r\n", $connection->writes[0]);
    }

    public function testMoveToFallsBackToCopyAndDeleteWithoutMoveCapability(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            'A0001 OK COPY done',
            'A0002 OK STORE done',
        );

        [$message] = $this->makeMessage($connection);

        $message->moveTo('Archive');

        self::assertSame("A0001 UID COPY 42 Archive\r\n", $connection->writes[0]);
        self::assertStringStartsWith('A0002 UID STORE 42 +FLAGS (\\Deleted)', $connection->writes[1]);
    }

    public function testRawBodyFetchesAndCaches(): void
    {
        $connection = new FakeConnection();
        $body = "Subject: Hi\r\n\r\nHello world";
        $connection->queueLines('* 1 FETCH (UID 42 BODY[] {' . strlen($body) . '}');
        $connection->queueBytes($body);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertSame($body, $message->rawBody());

        $writeCount = count($connection->writes);
        self::assertSame($body, $message->rawBody());
        self::assertCount($writeCount, $connection->writes, 'rawBody() must cache the result');
    }

    public function testBodyStructureFetchesAndCaches(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL))',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        $structure = $message->bodyStructure();
        self::assertSame('TEXT', $structure->type);
        self::assertSame('PLAIN', $structure->subtype);

        $writeCount = count($connection->writes);
        $message->bodyStructure();
        self::assertCount($writeCount, $connection->writes, 'bodyStructure() must cache the result');
    }

    public function testHtmlAndTextAndHeadersFromMimeParser(): void
    {
        $connection = new FakeConnection();
        $body = "Subject: Hello world\r\nFrom: test@example.com\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nThis is the body.";
        $connection->queueLines('* 1 FETCH (UID 42 BODY[] {' . strlen($body) . '}');
        $connection->queueBytes($body);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertFalse($message->hasHtml());
        self::assertNull($message->html());
        self::assertSame('This is the body.', trim($message->text() ?? ''));
        self::assertSame('Hello world', $message->header('subject'));
        self::assertArrayHasKey('Subject', $message->headers());
    }

    public function testSaveWritesRawBodyToFile(): void
    {
        $connection = new FakeConnection();
        $body = "Hello";
        $connection->queueLines('* 1 FETCH (UID 42 BODY[] {' . strlen($body) . '}');
        $connection->queueBytes($body);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        $tmpDir = sys_get_temp_dir() . '/imapclient-test-' . uniqid('', true);
        $tmpFile = $tmpDir . '/nested/dir/message.eml';

        try {
            $message->save($tmpFile);

            self::assertFileExists($tmpFile);
            self::assertSame($body, file_get_contents($tmpFile));
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }
            // Clean up directory tree
            @rmdir(dirname($tmpFile));
            @rmdir(dirname(dirname($tmpFile)));
            @rmdir($tmpDir);
        }
    }

    public function testAttachmentsReturnsEmptyForSimpleTextMessage(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL))',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        $attachments = $message->attachments();
        self::assertInstanceOf(AttachmentCollection::class, $attachments);
        self::assertSame(0, $attachments->count());
    }
}
