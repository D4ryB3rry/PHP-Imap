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
        private readonly Transceiver $transceiver,
        private readonly Uid $messageUid,
        private readonly BodyStructure $structure,
        private readonly string $folderPath,
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

        $section = $this->structure->partNumber;
        $response = $this->transceiver->command(
            'UID FETCH',
            (string) $this->messageUid->value,
            sprintf('(BODY.PEEK[%s])', $section),
        );

        $data = '';
        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'FETCH' && is_array($untagged->data)) {
                $key = 'BODY[' . $section . ']';
                $data = $untagged->data[$key] ?? '';
                break;
            }
        }

        $this->cachedContent = $this->decodeContent($data);

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

    public function save(string $directoryPath): void
    {
        $directoryPath = rtrim($directoryPath, '/');
        $filename = $this->filename();
        $path = $directoryPath . '/' . $filename;

        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
        }

        file_put_contents($path, $this->content());
    }

    public function encoding(): ?ContentTransferEncoding
    {
        return $this->structure->encoding;
    }

    public function bodyStructure(): BodyStructure
    {
        return $this->structure;
    }

    private function decodeContent(string $raw): string
    {
        return match ($this->structure->encoding) {
            ContentTransferEncoding::Base64 => base64_decode(str_replace(["\r", "\n"], '', $raw), true) ?: '',
            ContentTransferEncoding::QuotedPrintable => quoted_printable_decode($raw),
            default => $raw,
        };
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
