<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Command;

use D4ry\ImapClient\ValueObject\Tag;

readonly class Command
{
    /**
     * @param string[] $arguments
     */
    public function __construct(
        public Tag $tag,
        public string $name,
        public array $arguments = [],
    ) {
    }

    public function compile(): string
    {
        $parts = [$this->tag->value, $this->name];

        foreach ($this->arguments as $arg) {
            $parts[] = $arg;
        }

        return implode(' ', $parts) . "\r\n";
    }
}
