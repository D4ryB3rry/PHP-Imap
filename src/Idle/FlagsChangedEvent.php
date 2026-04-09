<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

use D4ry\ImapClient\ValueObject\FlagSet;

/**
 * @infection-ignore-all
 */
class FlagsChangedEvent extends IdleEvent
{
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
        public readonly FlagSet $flags,
    ) {
        parent::__construct($rawLine);
    }
}
