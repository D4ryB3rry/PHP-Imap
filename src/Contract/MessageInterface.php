<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Contract;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\SequenceNumber;
use D4ry\ImapClient\ValueObject\Uid;

interface MessageInterface
{
    public function uid(): Uid;

    public function sequenceNumber(): SequenceNumber;

    public function envelope(): Envelope;

    public function flags(): FlagSet;

    public function internalDate(): \DateTimeImmutable;

    public function size(): int;

    public function hasHtml(): bool;

    public function html(): ?string;

    public function text(): ?string;

    /**
     * @return array<string, string[]>
     */
    public function headers(): array;

    public function header(string $name): ?string;

    public function attachments(): AttachmentCollection;

    public function bodyStructure(): BodyStructure;

    public function rawBody(): string;

    public function save(string $path): void;

    public function setFlag(string ...$flags): void;

    public function clearFlag(string ...$flags): void;

    public function moveTo(FolderInterface|string $folder): void;

    public function copyTo(FolderInterface|string $folder): void;

    public function delete(): void;

    public function emailId(): ?string;

    public function threadId(): ?string;

    public function modSeq(): ?int;
}
