<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

use D4ry\ImapClient\Enum\Flag;

readonly class FlagSet
{
    /** @var string[] */
    public array $flags;

    /**
     * @param array<Flag|string> $flags
     */
    public function __construct(array $flags = [])
    {
        $normalized = [];
        foreach ($flags as $flag) {
            $normalized[] = $flag instanceof Flag ? $flag->value : $flag;
        }

        $this->flags = array_unique($normalized);
    }

    public function has(Flag|string $flag): bool
    {
        $value = $flag instanceof Flag ? $flag->value : $flag;

        return in_array($value, $this->flags, true);
    }

    public function add(Flag|string ...$flags): self
    {
        $merged = $this->flags;
        foreach ($flags as $flag) {
            $merged[] = $flag instanceof Flag ? $flag->value : $flag;
        }

        return new self($merged);
    }

    public function remove(Flag|string ...$flags): self
    {
        $remove = [];
        foreach ($flags as $flag) {
            $remove[] = $flag instanceof Flag ? $flag->value : $flag;
        }

        return new self(array_diff($this->flags, $remove));
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
