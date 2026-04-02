<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

class IdleHeartbeatEvent extends IdleEvent
{
    public function __construct(
        string $rawLine,
        public readonly string $text,
    ) {
        parent::__construct($rawLine);
    }
}
