<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Contract;

interface AttachmentInterface
{
    public function filename(): string;

    public function mimeType(): string;

    public function size(): int;

    public function content(): string;

    public function isInline(): bool;

    public function contentId(): ?string;

    public function save(string $directoryPath, ?string $filename = null): void;

    /**
     * Stream the decoded attachment body into the given writable resource.
     * Transfer-encoding handling happens internally via a stream filter,
     * so memory usage stays at chunk size regardless of attachment size.
     *
     * @param resource $sink
     */
    public function streamTo($sink): void;
}
