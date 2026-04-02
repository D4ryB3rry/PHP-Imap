<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

class RecentCountEvent extends IdleEvent
{
    public function __construct(
        string $rawLine,
        public readonly int $count,
    ) {
        parent::__construct($rawLine);
    }
}
