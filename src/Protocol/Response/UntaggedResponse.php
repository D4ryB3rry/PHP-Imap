<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

class UntaggedResponse
{
    public function __construct(
        public string $type,
        public mixed $data = null,
        public ?string $raw = null,
    ) {
    }
}
