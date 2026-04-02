<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

readonly class MailboxPath
{
    public function __construct(
        public string $path,
        public string $delimiter = '/',
    ) {
    }

    public function name(): string
    {
        if ($this->delimiter === '') {
            return $this->path;
        }

        $parts = explode($this->delimiter, $this->path);

        return end($parts);
    }

    public function parent(): ?self
    {
        if ($this->delimiter === '') {
            return null;
        }

        $pos = strrpos($this->path, $this->delimiter);

        if ($pos === false) {
            return null;
        }

        return new self(substr($this->path, 0, $pos), $this->delimiter);
    }

    public function child(string $name): self
    {
        return new self($this->path . $this->delimiter . $name, $this->delimiter);
    }

    public function __toString(): string
    {
        return $this->path;
    }
}
