<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Support;

class Literal
{
    public function __construct(
        public string $data,
        public bool $nonSynchronizing = false,
    ) {
    }

    public function size(): int
    {
        return strlen($this->data);
    }

    public function toImapString(): string
    {
        $suffix = $this->nonSynchronizing ? '+' : '';

        return '{' . $this->size() . $suffix . "}\r\n" . $this->data;
    }
}
