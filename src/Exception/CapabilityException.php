<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Exception;

class CapabilityException extends ImapException
{
    public function __construct(public string $capability)
    {
        parent::__construct(
            sprintf('Required IMAP capability not available: %s', $capability)
        );
    }
}
