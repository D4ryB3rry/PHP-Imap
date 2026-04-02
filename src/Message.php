<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Contract\MessageInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\Mime\MimeParser;
use D4ry\ImapClient\Mime\ParsedMessage;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\ValueObject\BodyStructure;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\SequenceNumber;
use D4ry\ImapClient\ValueObject\Uid;

class Message implements MessageInterface
{
    private ?ParsedMessage $parsedMessage = null;
    private ?string $rawBodyCache = null;
    private ?BodyStructure $bodyStructureCache = null;

    public function __construct(
        private readonly Transceiver $transceiver,
        private readonly Uid $uid,
        private readonly SequenceNumber $sequenceNumber,
        private readonly Envelope $envelope,
        private FlagSet $flags,
        private readonly \DateTimeImmutable $internalDate,
        private readonly int $size,
        private readonly string $folderPath,
        private readonly ?string $emailIdValue = null,
        private readonly ?string $threadIdValue = null,
        private readonly ?int $modSeqValue = null,
    ) {
    }

    public function uid(): Uid
    {
        return $this->uid;
    }

    public function sequenceNumber(): SequenceNumber
    {
        return $this->sequenceNumber;
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function flags(): FlagSet
    {
        return $this->flags;
    }

    public function internalDate(): \DateTimeImmutable
    {
        return $this->internalDate;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function hasHtml(): bool
    {
        $parsed = $this->getParsedMessage();

        return $parsed->htmlBody !== null;
    }

    public function html(): ?string
    {
        return $this->getParsedMessage()->htmlBody;
    }

    public function text(): ?string
    {
        return $this->getParsedMessage()->textBody;
    }

    public function headers(): array
    {
        return $this->getParsedMessage()->headers;
    }

    public function header(string $name): ?string
    {
        return $this->getParsedMessage()->header($name);
    }

    public function attachments(): AttachmentCollection
    {
        $structure = $this->bodyStructure();
        $attachments = $this->collectAttachments($structure);

        return new AttachmentCollection($attachments);
    }

    public function bodyStructure(): BodyStructure
    {
        if ($this->bodyStructureCache !== null) {
            return $this->bodyStructureCache;
        }

        $this->ensureSelected();

        $response = $this->transceiver->command(
            'UID FETCH',
            (string) $this->uid->value,
            '(BODYSTRUCTURE)',
        );

        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'FETCH' && is_array($untagged->data)) {
                if (isset($untagged->data['BODYSTRUCTURE'])) {
                    $this->bodyStructureCache = $untagged->data['BODYSTRUCTURE'];

                    return $this->bodyStructureCache;
                }
            }
        }

        $this->bodyStructureCache = new BodyStructure('TEXT', 'PLAIN');

        return $this->bodyStructureCache;
    }

    public function rawBody(): string
    {

        if ($this->rawBodyCache !== null) {
            return $this->rawBodyCache;
        }

        $this->ensureSelected();
        $response = $this->transceiver->command(
            'UID FETCH',
            (string) $this->uid->value,
            '(BODY.PEEK[])',
        );
        foreach ($response->untagged as $untagged) {
            if (
                $untagged->type === 'FETCH' &&
                is_array($untagged->data)) {
                $this->rawBodyCache = $untagged->data['BODY[]'] ?? '';
                return $this->rawBodyCache;
            }
        }

        $this->rawBodyCache = '';

        return $this->rawBodyCache;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        file_put_contents($path, $this->rawBody());
    }

    public function setFlag(Flag ...$flags): void
    {
        $this->ensureSelected();

        $flagStrings = array_map(fn(Flag $f) => $f->value, $flags);
        $this->transceiver->command(
            'UID STORE',
            (string) $this->uid->value,
            '+FLAGS',
            '(' . implode(' ', $flagStrings) . ')',
        );

        $this->flags = $this->flags->add(...$flags);
    }

    public function clearFlag(Flag ...$flags): void
    {
        $this->ensureSelected();

        $flagStrings = array_map(fn(Flag $f) => $f->value, $flags);
        $this->transceiver->command(
            'UID STORE',
            (string) $this->uid->value,
            '-FLAGS',
            '(' . implode(' ', $flagStrings) . ')',
        );

        $this->flags = $this->flags->remove(...$flags);
    }

    public function moveTo(FolderInterface|string $folder): void
    {
        $this->ensureSelected();

        $targetPath = $folder instanceof FolderInterface ? (string) $folder->path() : $folder;
        $encoded = CommandBuilder::encodeMailboxName($targetPath, $this->transceiver->isUtf8Enabled());

        if ($this->transceiver->hasCapability(Capability::Move)) {
            $this->transceiver->command('UID MOVE', (string) $this->uid->value, $encoded);
        } else {
            $this->copyTo($folder);
            $this->delete();
        }
    }

    public function copyTo(FolderInterface|string $folder): void
    {
        $this->ensureSelected();

        $targetPath = $folder instanceof FolderInterface ? (string) $folder->path() : $folder;
        $encoded = CommandBuilder::encodeMailboxName($targetPath, $this->transceiver->isUtf8Enabled());

        $this->transceiver->command('UID COPY', (string) $this->uid->value, $encoded);
    }

    public function delete(): void
    {
        $this->setFlag(Flag::Deleted);
    }

    public function emailId(): ?string
    {
        return $this->emailIdValue;
    }

    public function threadId(): ?string
    {
        return $this->threadIdValue;
    }

    public function modSeq(): ?int
    {
        return $this->modSeqValue;
    }

    private function getParsedMessage(): ParsedMessage
    {
        if ($this->parsedMessage !== null) {
            return $this->parsedMessage;
        }

        $raw = $this->rawBody();
        $parser = new MimeParser();
        $this->parsedMessage = $parser->parse($raw);

        return $this->parsedMessage;
    }

    /**
     * @return AttachmentInterface[]
     */
    private function collectAttachments(BodyStructure $structure): array
    {
        $attachments = [];

        if ($structure->isMultipart()) {
            foreach ($structure->parts as $part) {
                $attachments = array_merge($attachments, $this->collectAttachments($part));
            }
        } elseif ($structure->isAttachment() || $structure->isInline()) {
            $attachments[] = new Attachment(
                $this->transceiver,
                $this->uid,
                $structure,
                $this->folderPath,
            );
        }

        return $attachments;
    }

    private function ensureSelected(): void
    {
        if ($this->transceiver->selectedMailbox !== $this->folderPath) {
            $encoded = CommandBuilder::encodeMailboxName(
                $this->folderPath,
                $this->transceiver->isUtf8Enabled(),
            );
            $this->transceiver->command('SELECT', $encoded);
            $this->transceiver->selectedMailbox = $this->folderPath;
        }
    }
}
