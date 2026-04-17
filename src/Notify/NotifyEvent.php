<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

abstract class NotifyEvent
{
    public readonly float $timestamp;

    public function __construct(
        public readonly string $rawLine,
    ) {
        $this->timestamp = microtime(true);
    }
}
