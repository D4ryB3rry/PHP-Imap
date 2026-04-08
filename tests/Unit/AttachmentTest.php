<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Attachment;
use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(Attachment::class)]
#[UsesClass(Transceiver::class)]
#[UsesClass(BodyStructure::class)]
#[UsesClass(Uid::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\Command::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Command\CommandBuilder::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\Response::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\ResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\FetchResponseParser::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\Response\UntaggedResponse::class)]
#[UsesClass(\D4ry\ImapClient\Protocol\TagGenerator::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Tag::class)]
final class AttachmentTest extends TestCase
{
    /** @var string[] */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            $this->removePath($path);
        }
        $this->tempPaths = [];
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }

        @rmdir($path);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/imapclient-attachment-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    private function makeStructure(
        string $type = 'APPLICATION',
        string $subtype = 'PDF',
        array $parameters = [],
        ?string $id = null,
        ?ContentTransferEncoding $encoding = ContentTransferEncoding::SevenBit,
        int $size = 123,
        ?string $disposition = null,
        ?string $dispositionFilename = null,
        string $partNumber = '2',
    ): BodyStructure {
        return new BodyStructure(
            type: $type,
            subtype: $subtype,
            parameters: $parameters,
            id: $id,
            description: null,
            encoding: $encoding,
            size: $size,
            parts: [],
            disposition: $disposition,
            dispositionFilename: $dispositionFilename,
            partNumber: $partNumber,
        );
    }

    /**
     * @return array{0: Attachment, 1: Transceiver}
     */
    private function makeAttachment(
        FakeConnection $connection,
        BodyStructure $structure,
        bool $preselect = true,
        string $folderPath = 'INBOX',
        int $uid = 42,
    ): array {
        $transceiver = new Transceiver($connection);

        if ($preselect) {
            $transceiver->selectedMailbox = $folderPath;
        }

        $attachment = new Attachment(
            transceiver: $transceiver,
            messageUid: new Uid($uid),
            structure: $structure,
            folderPath: $folderPath,
        );

        return [$attachment, $transceiver];
    }

    private function primeCachedContent(Attachment $attachment, string $content): void
    {
        $prop = new ReflectionProperty(Attachment::class, 'cachedContent');
        $prop->setValue($attachment, $content);
    }

    public function testFilenameReturnsDispositionFilename(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'doc.pdf');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame('doc.pdf', $attachment->filename());
    }

    public function testFilenameFallsBackToParameterName(): void
    {
        $structure = $this->makeStructure(parameters: ['name' => 'inline.png']);
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame('inline.png', $attachment->filename());
    }

    public function testFilenameDefaultsToUnnamedWhenMissing(): void
    {
        $structure = $this->makeStructure();
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame('unnamed', $attachment->filename());
    }

    public function testMimeTypeIsLowercased(): void
    {
        $structure = $this->makeStructure(type: 'APPLICATION', subtype: 'PDF');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame('application/pdf', $attachment->mimeType());
    }

    public function testSizeReturnsStructureSize(): void
    {
        $structure = $this->makeStructure(size: 4096);
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame(4096, $attachment->size());
    }

    public function testIsInlineReturnsTrueWhenStructureIsInline(): void
    {
        $structure = $this->makeStructure(id: '<cid@x>', disposition: 'inline');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertTrue($attachment->isInline());
    }

    public function testIsInlineReturnsFalseForRegularAttachment(): void
    {
        $structure = $this->makeStructure(disposition: 'attachment');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertFalse($attachment->isInline());
    }

    public function testContentIdReturnsStructureId(): void
    {
        $withId = $this->makeStructure(id: '<cid@x>');
        [$a1] = $this->makeAttachment(new FakeConnection(), $withId);
        self::assertSame('<cid@x>', $a1->contentId());

        $withoutId = $this->makeStructure();
        [$a2] = $this->makeAttachment(new FakeConnection(), $withoutId);
        self::assertNull($a2->contentId());
    }

    public function testEncodingReturnsStructureEncoding(): void
    {
        $withEncoding = $this->makeStructure(encoding: ContentTransferEncoding::Base64);
        [$a1] = $this->makeAttachment(new FakeConnection(), $withEncoding);
        self::assertSame(ContentTransferEncoding::Base64, $a1->encoding());

        $withoutEncoding = $this->makeStructure(encoding: null);
        [$a2] = $this->makeAttachment(new FakeConnection(), $withoutEncoding);
        self::assertNull($a2->encoding());
    }

    public function testBodyStructureReturnsInjectedStructure(): void
    {
        $structure = $this->makeStructure();
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);

        self::assertSame($structure, $attachment->bodyStructure());
    }

    public function testContentFetchesAndCachesWhenAlreadySelected(): void
    {
        $payload = 'binary-bytes-here';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::SevenBit, partNumber: '2');
        [$attachment] = $this->makeAttachment($connection, $structure);

        self::assertSame($payload, $attachment->content());
        self::assertCount(1, $connection->writes);
        self::assertSame("A0001 UID FETCH 42 (BODY.PEEK[2])\r\n", $connection->writes[0]);

        // Cached call must not issue another command.
        self::assertSame($payload, $attachment->content());
        self::assertCount(1, $connection->writes, 'content() must cache the result');
    }

    public function testContentTriggersSelectWhenFolderNotSelected(): void
    {
        $payload = 'hello';
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK SELECT done');
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0002 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::SevenBit, partNumber: '2');
        [$attachment, $transceiver] = $this->makeAttachment($connection, $structure, preselect: false);

        self::assertSame($payload, $attachment->content());
        self::assertSame("A0001 SELECT INBOX\r\n", $connection->writes[0]);
        self::assertSame("A0002 UID FETCH 42 (BODY.PEEK[2])\r\n", $connection->writes[1]);
        self::assertSame('INBOX', $transceiver->selectedMailbox);
    }

    public function testContentDecodesBase64AndStripsLineBreaks(): void
    {
        $decoded = 'hello world from base64 attachment payload';
        $encoded = chunk_split(base64_encode($decoded), 16, "\r\n"); // forces \r\n in payload

        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($encoded) . '}');
        $connection->queueBytes($encoded);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::Base64, partNumber: '2');
        [$attachment] = $this->makeAttachment($connection, $structure);

        self::assertSame($decoded, $attachment->content());
    }

    public function testContentReturnsEmptyStringWhenBase64Invalid(): void
    {
        $payload = '!!!not_base64!!!';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::Base64, partNumber: '2');
        [$attachment] = $this->makeAttachment($connection, $structure);

        self::assertSame('', $attachment->content());
    }

    public function testContentDecodesQuotedPrintable(): void
    {
        $payload = 'Hello=20World=3D!';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::QuotedPrintable, partNumber: '2');
        [$attachment] = $this->makeAttachment($connection, $structure);

        self::assertSame('Hello World=!', $attachment->content());
    }

    public function testContentReturnsEmptyStringWhenFetchResponseHasNoMatchingSection(): void
    {
        // Structure asks for part '2', but the server replies with BODY[1] only.
        $payload = 'unrelated';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[1] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $structure = $this->makeStructure(encoding: ContentTransferEncoding::SevenBit, partNumber: '2');
        [$attachment] = $this->makeAttachment($connection, $structure);

        self::assertSame('', $attachment->content());

        // Cached: subsequent call issues no extra commands.
        self::assertSame('', $attachment->content());
        self::assertCount(1, $connection->writes);
    }

    public function testSaveWritesContentToFileUsingFilenameFromStructure(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'report.pdf');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);
        $this->primeCachedContent($attachment, 'PDF-PAYLOAD');

        $dir = $this->makeTempDir();
        $attachment->save($dir);

        self::assertFileExists($dir . '/report.pdf');
        self::assertSame('PDF-PAYLOAD', file_get_contents($dir . '/report.pdf'));
    }

    public function testSaveCreatesNestedDirectories(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'note.txt');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);
        $this->primeCachedContent($attachment, 'note-content');

        $base = $this->makeTempDir();
        $nested = $base . '/nested/deep';
        $attachment->save($nested);

        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/note.txt');
        self::assertSame('note-content', file_get_contents($nested . '/note.txt'));
    }

    public function testSaveUsesExplicitFilenameAndStripsPathTraversal(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'safe.txt');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);
        $this->primeCachedContent($attachment, 'safe-content');

        $dir = $this->makeTempDir();
        $attachment->save($dir, '../evil.txt');

        self::assertFileExists($dir . '/evil.txt');
        self::assertFileDoesNotExist(dirname($dir) . '/evil.txt');
    }

    public function testSaveAcceptsTrailingSlashInDirectoryPath(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'trail.txt');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);
        $this->primeCachedContent($attachment, 'trail');

        $dir = $this->makeTempDir();
        $attachment->save($dir . '/');

        self::assertFileExists($dir . '/trail.txt');
    }

    public function testSaveThrowsWhenDirectoryCannotBeCreated(): void
    {
        $structure = $this->makeStructure(dispositionFilename: 'doomed.txt');
        [$attachment] = $this->makeAttachment(new FakeConnection(), $structure);
        $this->primeCachedContent($attachment, 'never-written');

        // Create a regular file, then attempt to use a path *under* it as a directory.
        // mkdir() will fail because a non-directory exists in the parent path.
        $base = $this->makeTempDir();
        $blockingFile = $base . '/blocker';
        file_put_contents($blockingFile, 'x');
        $impossibleDir = $blockingFile . '/sub';

        // mkdir() emits an E_WARNING on failure; swallow it so PHPUnit's
        // failOnWarning setting does not turn the expected failure into a test failure.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(sprintf('Directory "%s" was not created', $impossibleDir));

            $attachment->save($impossibleDir);
        } finally {
            restore_error_handler();
        }
    }
}
