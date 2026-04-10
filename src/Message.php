<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Contract\MessageInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Mime\HeaderDecoder;
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
    private ?string $textBodyCache = null;
    private bool $textBodyResolved = false;
    private ?string $htmlBodyCache = null;
    private bool $htmlBodyResolved = false;

    public function __construct(
        private Transceiver $transceiver,
        private Uid $uid,
        private SequenceNumber $sequenceNumber,
        private Envelope $envelope,
        private FlagSet $flags,
        private \DateTimeImmutable $internalDate,
        private int $size,
        private string $folderPath,
        private ?string $emailIdValue = null,
        private ?string $threadIdValue = null,
        private ?int $modSeqValue = null,
        ?BodyStructure $bodyStructure = null,
    ) {
        $this->bodyStructureCache = $bodyStructure;
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
        return $this->html() !== null;
    }

    /**
     * @infection-ignore-all
     */
    public function html(): ?string
    {
        if ($this->htmlBodyResolved) {
            return $this->htmlBodyCache;
        }

        $this->htmlBodyCache = $this->fetchTextPart('html');
        $this->htmlBodyResolved = true;

        return $this->htmlBodyCache;
    }

    /**
     * @infection-ignore-all
     */
    public function text(): ?string
    {
        if ($this->textBodyResolved) {
            return $this->textBodyCache;
        }

        $this->textBodyCache = $this->fetchTextPart('plain');
        $this->textBodyResolved = true;

        return $this->textBodyCache;
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

    /**
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        file_put_contents($path, $this->rawBody());
    }

    public function setFlag(string ...$flags): void
    {
        $this->ensureSelected();

        $this->transceiver->command(
            'UID STORE',
            (string) $this->uid->value,
            '+FLAGS',
            '(' . implode(' ', $flags) . ')',
        );

        $this->flags = $this->flags->add(...$flags);
    }

    /**
     * @infection-ignore-all
     */
    public function clearFlag(string ...$flags): void
    {
        $this->ensureSelected();

        $this->transceiver->command(
            'UID STORE',
            (string) $this->uid->value,
            '-FLAGS',
            '(' . implode(' ', $flags) . ')',
        );

        $this->flags = $this->flags->remove(...$flags);
    }

    /**
     * @infection-ignore-all
     */
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

    /**
     * @infection-ignore-all
     */
    public function copyTo(FolderInterface|string $folder): void
    {
        $this->ensureSelected();

        $targetPath = $folder instanceof FolderInterface ? (string) $folder->path() : $folder;
        $encoded = CommandBuilder::encodeMailboxName($targetPath, $this->transceiver->isUtf8Enabled());

        $this->transceiver->command('UID COPY', (string) $this->uid->value, $encoded);
    }

    public function delete(): void
    {
        $this->setFlag(\D4ry\ImapClient\Enum\Flag::Deleted);
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

    /**
     * @infection-ignore-all
     */
    private function fetchTextPart(string $subtype): ?string
    {
        $structure = $this->bodyStructure();
        $part = $this->findTextPart($structure, $subtype);
        if ($part === null) {
            return null;
        }

        $this->ensureSelected();

        $section = $part->partNumber;
        $response = $this->transceiver->command(
            'UID FETCH',
            (string) $this->uid->value,
            sprintf('(BODY.PEEK[%s])', $section),
        );

        $raw = '';
        $key = 'BODY[' . $section . ']';
        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'FETCH' && is_array($untagged->data) && isset($untagged->data[$key])) {
                $raw = (string) $untagged->data[$key];
                break;
            }
        }

        $decoded = match ($part->encoding) {
            ContentTransferEncoding::Base64 => base64_decode(str_replace(["\r", "\n"], '', $raw), true) ?: '',
            ContentTransferEncoding::QuotedPrintable => quoted_printable_decode($raw),
            default => $raw,
        };

        $charset = $part->charset() ?? 'UTF-8';

        return HeaderDecoder::convertToUtf8($decoded, $charset);
    }

    /**
     * @infection-ignore-all
     */
    private function findTextPart(BodyStructure $structure, string $subtype): ?BodyStructure
    {
        if (!$structure->isMultipart()) {
            if (
                strtolower($structure->type) === 'text'
                && strtolower($structure->subtype) === $subtype
                && !$structure->isAttachment()
            ) {
                return $structure;
            }

            return null;
        }

        foreach ($structure->parts as $part) {
            $found = $this->findTextPart($part, $subtype);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @infection-ignore-all
     */
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
    /**
     * @infection-ignore-all
     */
    private function collectAttachments(BodyStructure $structure): array
    {
        $attachments = [];
        $this->walkAttachments($structure, $attachments);

        return $attachments;
    }

    /**
     * Recursive walker that pushes Attachment objects into an accumulator
     * passed by reference. Avoids the O(n²) `array_merge` in a loop pattern
     * the previous recursive implementation used.
     *
     * @param AttachmentInterface[] $accumulator
     */
    /**
     * @infection-ignore-all
     */
    private function walkAttachments(BodyStructure $structure, array &$accumulator): void
    {
        if ($structure->isMultipart()) {
            foreach ($structure->parts as $part) {
                $this->walkAttachments($part, $accumulator);
            }

            return;
        }

        if ($structure->isAttachment() || $structure->isInline()) {
            $accumulator[] = new Attachment(
                $this->transceiver,
                $this->uid,
                $structure,
                $this->folderPath,
            );
        }
    }

    /**
     * @infection-ignore-all
     */
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
