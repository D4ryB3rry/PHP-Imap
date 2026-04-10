<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Mime;

class ParsedMessage
{
    /**
     * @param array<string, string[]> $headers
     * @param ParsedPart[]            $parts
     */
    public function __construct(
        public array $headers,
        public ?string $textBody,
        public ?string $htmlBody,
        public array $parts = [],
    ) {
    }

    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function headerAll(string $name): array
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @return ParsedPart[]
     */
    public function attachments(): array
    {
        return array_filter($this->parts, fn(ParsedPart $p) => $p->filename !== null && !$p->isInline);
    }

    /**
     * @return ParsedPart[]
     */
    public function inlineParts(): array
    {
        return array_filter($this->parts, fn(ParsedPart $p) => $p->isInline);
    }
}
