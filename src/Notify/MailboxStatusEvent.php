<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * NOTIFY-triggered `* STATUS mbox (...)` for a mailbox other than the
 * currently-selected one. Attribute set is server-chosen; consumers must
 * tolerate any subset.
 */
final class MailboxStatusEvent extends NotifyEvent
{
    /**
     * @param array<string, int|string> $attributes Uppercase attribute name → value (int for counters, string for MAILBOXID).
     */
    public function __construct(
        string $rawLine,
        public readonly string $mailbox,
        public readonly array $attributes,
    ) {
        parent::__construct($rawLine);
    }
}
