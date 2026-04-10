<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class Tag
{
    public function __construct(
        public string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
