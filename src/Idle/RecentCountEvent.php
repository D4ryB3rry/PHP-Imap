<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

/**
 * @infection-ignore-all
 */
class RecentCountEvent extends IdleEvent
{
    public function __construct(
        string $rawLine,
        public int $count,
    ) {
        parent::__construct($rawLine);
    }
}
