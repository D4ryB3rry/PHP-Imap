<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class FlagSet
{
    /** @var string[] */
    public array $flags;

    /**
     * @param string[] $flags
     */
    public function __construct(array $flags = [])
    {
        $this->flags = array_values(array_unique($flags));
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    public function add(string ...$flags): self
    {
        return new self(array_merge($this->flags, $flags));
    }

    public function remove(string ...$flags): self
    {
        return new self(array_diff($this->flags, $flags));
    }

    public function toImapString(): string
    {
        return '(' . implode(' ', $this->flags) . ')';
    }

    public function isEmpty(): bool
    {
        return $this->flags === [];
    }

    public function count(): int
    {
        return count($this->flags);
    }
}
