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
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \D4ry\ImapClient\Message
 * @uses \D4ry\ImapClient\Protocol\Transceiver
 * @uses \D4ry\ImapClient\Attachment
 * @uses \D4ry\ImapClient\Collection\AttachmentCollection
 * @uses \D4ry\ImapClient\ValueObject\MailboxPath
 * @uses \D4ry\ImapClient\ValueObject\Uid
 * @uses \D4ry\ImapClient\ValueObject\SequenceNumber
 * @uses \D4ry\ImapClient\ValueObject\FlagSet
 * @uses \D4ry\ImapClient\ValueObject\Envelope
 * @uses \D4ry\ImapClient\ValueObject\Address
 * @uses \D4ry\ImapClient\ValueObject\BodyStructure
 * @uses \D4ry\ImapClient\Mime\MimeParser
 * @uses \D4ry\ImapClient\Mime\HeaderDecoder
 * @uses \D4ry\ImapClient\Mime\ParsedMessage
 * @uses \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\Protocol\Command\CommandBuilder
 * @uses \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\ResponseParser
 * @uses \D4ry\ImapClient\Protocol\Response\FetchResponseParser
 * @uses \D4ry\ImapClient\Protocol\Response\UntaggedResponse
 * @uses \D4ry\ImapClient\Protocol\TagGenerator
 * @uses \D4ry\ImapClient\ValueObject\Tag
 */
final class MessageTest extends TestCase
{
    private function setCapabilities(Transceiver $transceiver, string ...$caps): void
    {
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, $caps);
    }

    private function makeMessage(
        FakeConnection $connection,
        int $uid = 42,
        ?FlagSet $flags = null,
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
            flags: $flags ?? new FlagSet(),
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
        [$message] = $this->makeMessage($connection, 42, new FlagSet([Flag::Seen]));

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

        [$message] = $this->makeMessage($connection, 42, new FlagSet([Flag::Seen]));

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

    public function testTextFetchesOnlyTextPartUsingBodyStructure(): void
    {
        $textPayload = 'This is the body.';
        $connection = new FakeConnection();

        // 1. text() → BODYSTRUCTURE for a single-part text/plain message.
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 17 1 NIL NIL))',
            'A0001 OK FETCH done',
        );
        // 2. BODY.PEEK[1] returns just the text part body.
        $connection->queueLines('* 1 FETCH (UID 42 BODY[1] {' . strlen($textPayload) . '}');
        $connection->queueBytes($textPayload);
        $connection->queueLines(
            ')',
            'A0002 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertSame($textPayload, $message->text());

        // Exactly two commands: BODYSTRUCTURE then BODY.PEEK[1] — never a full BODY[] fetch.
        self::assertCount(2, $connection->writes);
        self::assertSame("A0001 UID FETCH 42 (BODYSTRUCTURE)\r\n", $connection->writes[0]);
        self::assertSame("A0002 UID FETCH 42 (BODY.PEEK[1])\r\n", $connection->writes[1]);

        // Cached: subsequent calls send no new commands.
        self::assertSame($textPayload, $message->text());
        self::assertCount(2, $connection->writes);
    }

    public function testHtmlReturnsNullForPlainTextMessageWithoutFetchingFullBody(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 17 1 NIL NIL))',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertFalse($message->hasHtml());
        self::assertNull($message->html());
        // Only the BODYSTRUCTURE fetch happened — no BODY[] download.
        self::assertCount(1, $connection->writes);
        self::assertSame("A0001 UID FETCH 42 (BODYSTRUCTURE)\r\n", $connection->writes[0]);
    }

    public function testTextFetchesCorrectPartFromMultipartMixed(): void
    {
        $textPayload = 'Plain body of an email with a big PDF attachment.';
        $connection = new FakeConnection();

        // multipart/mixed with text/plain (part 1) + application/pdf attachment (part 2).
        $bodyStructure =
            '(' .
                '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 50 1 NIL NIL NIL)' .
                '("APPLICATION" "PDF" ("NAME" "big.pdf") NIL NIL "BASE64" 5000000 NIL ' .
                    '("ATTACHMENT" ("FILENAME" "big.pdf")) NIL NIL)' .
                ' "MIXED" NIL NIL NIL' .
            ')';

        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ' . $bodyStructure . ')',
            'A0001 OK FETCH done',
        );
        $connection->queueLines('* 1 FETCH (UID 42 BODY[1] {' . strlen($textPayload) . '}');
        $connection->queueBytes($textPayload);
        $connection->queueLines(
            ')',
            'A0002 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertSame($textPayload, $message->text());

        // Critically: the attachment part (BODY[2], 5 MB) is never requested.
        self::assertCount(2, $connection->writes);
        self::assertSame("A0002 UID FETCH 42 (BODY.PEEK[1])\r\n", $connection->writes[1]);
    }

    public function testTextDecodesBase64TextPart(): void
    {
        $decoded = 'Hällo Wörld — base64 + UTF-8';
        $encoded = base64_encode($decoded);

        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "BASE64" ' . strlen($encoded) . ' 1 NIL NIL))',
            'A0001 OK FETCH done',
        );
        $connection->queueLines('* 1 FETCH (UID 42 BODY[1] {' . strlen($encoded) . '}');
        $connection->queueBytes($encoded);
        $connection->queueLines(
            ')',
            'A0002 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertSame($decoded, $message->text());
    }

    public function testHeadersStillUseFullBodyFetch(): void
    {
        // headers()/header() continue to rely on rawBody() — verify the
        // original BODY[] path is unchanged for that consumer.
        $connection = new FakeConnection();
        $body = "Subject: Hello world\r\nFrom: test@example.com\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nThis is the body.";
        $connection->queueLines('* 1 FETCH (UID 42 BODY[] {' . strlen($body) . '}');
        $connection->queueBytes($body);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

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
            @rmdir(dirname($tmpFile, 2));
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

    public function testBodyStructureFallsBackToTextPlainWhenServerOmitsIt(): void
    {
        $connection = new FakeConnection();
        // FETCH untagged exists but does not include BODYSTRUCTURE → loop
        // skips it and the post-loop default kicks in.
        $connection->queueLines(
            '* 1 FETCH (UID 42)',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        $structure = $message->bodyStructure();

        self::assertSame('TEXT', $structure->type);
        self::assertSame('PLAIN', $structure->subtype);
    }

    public function testRawBodyReturnsEmptyStringWhenServerOmitsBody(): void
    {
        $connection = new FakeConnection();
        // No FETCH untagged at all → loop body never runs and the post-loop
        // empty-string fallback executes.
        $connection->queueLines('A0001 OK FETCH done');

        [$message] = $this->makeMessage($connection);

        self::assertSame('', $message->rawBody());
    }

    public function testSaveThrowsWhenDirectoryCannotBeCreated(): void
    {
        $connection = new FakeConnection();
        // rawBody() must succeed before save() reaches the mkdir branch — but
        // the mkdir failure throws first if we wire the path so the parent
        // directory exists as a regular file.
        $blocker = sys_get_temp_dir() . '/imap-save-blocker-' . uniqid('', true);
        file_put_contents($blocker, '');

        // mkdir() emits an E_WARNING when its parent path is a regular file;
        // PHPUnit's failOnWarning would otherwise turn it into a test failure.
        set_error_handler(static fn() => true, E_WARNING);

        try {
            [$message] = $this->makeMessage($connection);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/was not created/');

            $message->save($blocker . '/nested/file.eml');
        } finally {
            restore_error_handler();
            @unlink($blocker);
        }
    }

    public function testHtmlReturnsNullForMultipartWithoutHtmlPart(): void
    {
        // multipart/mixed with text/plain + application/pdf — no text/html.
        // Exercises the post-loop `return null` in findTextPart() after the
        // recursive walk visits every part without matching.
        $bodyStructure =
            '(' .
                '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL NIL)' .
                '("APPLICATION" "PDF" ("NAME" "doc.pdf") NIL NIL "BASE64" 1234 NIL ' .
                    '("ATTACHMENT" ("FILENAME" "doc.pdf")) NIL NIL)' .
                ' "MIXED" NIL NIL NIL' .
            ')';

        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ' . $bodyStructure . ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        self::assertNull($message->html());
        self::assertFalse($message->hasHtml());
    }

    public function testAttachmentsWalksMultipartAndExtractsAttachmentPart(): void
    {
        $connection = new FakeConnection();
        // multipart/mixed with one text/plain (no disposition → skipped) and
        // one application/pdf with an ATTACHMENT disposition (collected).
        $bodyStructure =
            '(' .
                '("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 12 1 NIL NIL NIL)' .
                '("APPLICATION" "PDF" ("NAME" "doc.pdf") NIL NIL "BASE64" 1234 NIL ' .
                    '("ATTACHMENT" ("FILENAME" "doc.pdf")) NIL NIL)' .
                ' "MIXED" NIL NIL NIL' .
            ')';

        $connection->queueLines(
            '* 1 FETCH (UID 42 BODYSTRUCTURE ' . $bodyStructure . ')',
            'A0001 OK FETCH done',
        );

        [$message] = $this->makeMessage($connection);

        $attachments = $message->attachments();

        self::assertSame(1, $attachments->count());
        self::assertSame('doc.pdf', $attachments[0]->filename());
    }
}
