<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Exception;

use D4ry\ImapClient\Enum\Capability;

class CapabilityException extends ImapException
{
    public function __construct(public readonly Capability $capability)
    {
        parent::__construct(
            sprintf('Required IMAP capability not available: %s', $capability->value)
        );
    }
}
