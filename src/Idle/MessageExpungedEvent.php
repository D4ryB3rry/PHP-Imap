<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

/**
 * @infection-ignore-all
 */
class MessageExpungedEvent extends IdleEvent
{
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
    ) {
        parent::__construct($rawLine);
    }
}
