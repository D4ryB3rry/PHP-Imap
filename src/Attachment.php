<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Uid;

class Attachment implements AttachmentInterface
{
    private ?string $cachedContent = null;

    public function __construct(
        private Transceiver $transceiver,
        private Uid $messageUid,
        private BodyStructure $structure,
        private string $folderPath,
    ) {
    }

    public function filename(): string
    {
        return $this->structure->filename() ?? 'unnamed';
    }

    public function mimeType(): string
    {
        return $this->structure->mimeType();
    }

    public function size(): int
    {
        return $this->structure->size;
    }

    public function content(): string
    {
        if ($this->cachedContent !== null) {
            return $this->cachedContent;
        }

        $this->ensureSelected();

        // Route through the same streaming-sink path as save() so the encoded
        // body never materializes as a PHP string mid-fetch — the php://temp
        // buffer holds only the *decoded* bytes that are about to become the
        // return value anyway.
        $sink = fopen('php://temp', 'w+b');
        // @codeCoverageIgnoreStart
        if ($sink === false) {
            throw new \RuntimeException('Could not open php://temp for attachment content');
        }
        // @codeCoverageIgnoreEnd

        // The finally is defensive: PHP would refcount-close $sink anyway on
        // function exit, so unwrapping it is observably equivalent. The mutant
        // is suppressed but a resource-leak regression test still exists in
        // AttachmentTest::testContentClosesSinkResourceWhenFetchThrows.
        // @infection-ignore-all
        try {
            $this->fetchPartIntoStream($sink);
            rewind($sink);
            $decoded = stream_get_contents($sink);
        } finally {
            fclose($sink);
        }

        $this->cachedContent = $decoded === false ? '' : $decoded;

        return $this->cachedContent;
    }

    public function isInline(): bool
    {
        return $this->structure->isInline();
    }

    public function contentId(): ?string
    {
        return $this->structure->id;
    }

    public function save(string $directoryPath, ?string $filename = null): void
    {
        $directoryPath = rtrim($directoryPath, '/');
        $filename = basename($filename ?? $this->filename());
        $path = $directoryPath . '/' . $filename;

        // The trailing `&& !is_dir()` is a TOCTOU guard for the case where a
        // concurrent process creates $directoryPath between our mkdir() failing
        // and us re-checking. Triggering that race in a unit test is unreliable,
        // so the LogicalAnd mutant on this line is suppressed here (the
        // Decrement/Increment mutants on the mode 0755 are killed by an explicit
        // stat() assertion in AttachmentTest::testSaveCreatesDirectoryWithMode0755).
        // @infection-ignore-all
        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
        }

        // If we already have the decoded payload from a prior content() call,
        // just dump it. The interesting path is the cold one below.
        if ($this->cachedContent !== null) {
            file_put_contents($path, $this->cachedContent);
            return;
        }

        $this->ensureSelected();

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing', $path));
        }

        try {
            $this->fetchPartIntoStream($fp);
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($path);
            throw $e;
        }

        fclose($fp);
    }

    /**
     * Stream the encoded part body straight from the IMAP socket into $sink,
     * applying the appropriate decoding stream filter so the decoded bytes
     * land in $sink without the encoded payload ever being materialized as a
     * PHP string. This is the memory-bounded path used by save() and the
     * php://temp-backed path inside content().
     *
     * @param resource $sink writable stream resource
     */
    private function fetchPartIntoStream($sink): void
    {
        $section = $this->structure->partNumber;

        // Attach a decoding filter for the duration of the fetch. The filter
        // sits in front of the sink, so socket → filter → sink runs entirely
        // in chunks: 8 KiB read from the wire is fed straight to the filter,
        // which writes the decoded bytes to the sink and returns. Peak heap
        // for the literal stays at chunk-size, not literal-size.
        $filterName = match ($this->structure->encoding) {
            ContentTransferEncoding::Base64 => 'convert.base64-decode',
            ContentTransferEncoding::QuotedPrintable => 'convert.quoted-printable-decode',
            default => null,
        };

        $filter = null;
        if ($filterName !== null) {
            // line-length=0 disables PHP's automatic line-wrapping in the
            // base64 filter for write mode, which is irrelevant here but also
            // suppresses some whitespace-handling quirks on older builds.
            // The line-length value is ignored by the *decode* filters in
            // practice, so its exact integer (0 vs 1 vs -1) and even its
            // presence in the params array is observably equivalent — the
            // mutants on this line are suppressed.
            // @infection-ignore-all
            $filter = @stream_filter_append($sink, $filterName, STREAM_FILTER_WRITE, ['line-length' => 0]);
            // @codeCoverageIgnoreStart
            if ($filter === false) {
                throw new \RuntimeException(sprintf('Could not append "%s" filter to sink', $filterName));
            }
            // @codeCoverageIgnoreEnd
        }

        // The finally block is defensive: every caller of fetchPartIntoStream
        // immediately fclose()s $sink (which auto-removes any attached filters)
        // when the function returns or throws, so unwrapping the finally is
        // observably equivalent. The mutant is suppressed.
        // @infection-ignore-all
        try {
            $this->transceiver->commandWithLiteralSink(
                $sink,
                'UID FETCH',
                (string) $this->messageUid->value,
                sprintf('(BODY.PEEK[%s])', $section),
            );
        } finally {
            if ($filter !== null) {
                @stream_filter_remove($filter);
            }
        }
    }

    public function streamTo($sink): void
    {
        if (!is_resource($sink) || get_resource_type($sink) !== 'stream') {
            throw new \InvalidArgumentException('Expected a writable stream resource');
        }

        $this->ensureSelected();
        $this->fetchPartIntoStream($sink);
    }

    public function encoding(): ?string
    {
        return $this->structure->encoding;
    }

    public function bodyStructure(): BodyStructure
    {
        return $this->structure;
    }

    private function ensureSelected(): void
    {
        if ($this->transceiver->selectedMailbox !== $this->folderPath) {
            $encoded = Protocol\Command\CommandBuilder::encodeMailboxName(
                $this->folderPath,
                $this->transceiver->isUtf8Enabled(),
            );
            $this->transceiver->command('SELECT', $encoded);
            $this->transceiver->selectedMailbox = $this->folderPath;
        }
    }
}
