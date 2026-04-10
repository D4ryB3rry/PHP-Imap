<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

abstract class IdleEvent
{
    public float $timestamp;

    public function __construct(
        public string $rawLine,
    ) {
        $this->timestamp = microtime(true);
    }
}
