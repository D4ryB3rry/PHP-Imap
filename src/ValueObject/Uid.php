<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

readonly class Uid
{
    public function __construct(
        public int $value,
    ) {
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
