<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

readonly class MailboxStatus
{
    public function __construct(
        public int $messages = 0,
        public int $recent = 0,
        public int $uidNext = 0,
        public int $uidValidity = 0,
        public int $unseen = 0,
        public ?int $highestModSeq = null,
        public ?int $size = null,
        public ?string $mailboxId = null,
    ) {
    }

    /**
     * Build from raw STATUS attribute map (uppercase keys → int values)
     * as returned by ResponseParser::parseStatusResponse().
     *
     * @param array<string, int|string> $attrs
     */
    public static function fromStatusAttributes(array $attrs): self
    {
        return new self(
            messages:     $attrs['MESSAGES'] ?? 0,
            recent:       $attrs['RECENT'] ?? 0,
            uidNext:      $attrs['UIDNEXT'] ?? 0,
            uidValidity:  $attrs['UIDVALIDITY'] ?? 0,
            unseen:       $attrs['UNSEEN'] ?? 0,
            highestModSeq: $attrs['HIGHESTMODSEQ'] ?? null,
            size:         $attrs['SIZE'] ?? null,
            mailboxId:    $attrs['MAILBOXID'] ?? null,
        );
    }
}
