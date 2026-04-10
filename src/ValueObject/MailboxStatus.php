<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class MailboxStatus
{
    public function __construct(
        public int $messages = 0,
        public int $recent = 0,
        public int $uidNext = 0,
        public int $uidValidity = 0,
        public int $unseen = 0,
        public ?int $highestModSeq = null,
        public ?int $size = null,
    ) {
    }
}
