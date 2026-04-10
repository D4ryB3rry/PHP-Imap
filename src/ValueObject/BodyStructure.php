<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class BodyStructure
{
    /**
     * @param array<string, string> $parameters
     * @param BodyStructure[]       $parts
     */
    public function __construct(
        public string $type,
        public string $subtype,
        public array $parameters = [],
        public ?string $id = null,
        public ?string $description = null,
        public ?string $encoding = null,
        public int $size = 0,
        public array $parts = [],
        public ?string $disposition = null,
        public ?string $dispositionFilename = null,
        public string $partNumber = '1',
    ) {
    }

    public function mimeType(): string
    {
        return strtolower($this->type . '/' . $this->subtype);
    }

    public function isMultipart(): bool
    {
        return strtolower($this->type) === 'multipart';
    }

    public function isAttachment(): bool
    {
        if (strtolower($this->disposition ?? '') === 'attachment') {
            return true;
        }

        if ($this->dispositionFilename !== null) {
            return true;
        }

        $filename = $this->parameters['name'] ?? null;

        return $filename !== null && strtolower($this->disposition ?? '') !== 'inline';
    }

    public function isInline(): bool
    {
        return strtolower($this->disposition ?? '') === 'inline' && $this->id !== null;
    }

    public function filename(): ?string
    {
        return $this->dispositionFilename
            ?? $this->parameters['name']
            ?? null;
    }

    public function charset(): ?string
    {
        return $this->parameters['charset'] ?? null;
    }
}
