<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class Address
{
    public function __construct(
        public ?string $name,
        public string $mailbox,
        public string $host,
    ) {
    }

    public function email(): string
    {
        return $this->mailbox . '@' . $this->host;
    }

    public function __toString(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return sprintf('"%s" <%s>', $this->name, $this->email());
        }

        return $this->email();
    }
}
