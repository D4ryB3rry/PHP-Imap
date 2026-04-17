<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 MailboxMetadataChange: a `* METADATA "mbox" (entry value ...)`
 * untagged response. The entry list is surfaced verbatim (after the mailbox
 * argument) — consumers that care about the individual entry/value pairs
 * can parse {@see $rawEntries} themselves against RFC 5464 §4.4.
 */
final class MailboxMetadataChangeEvent extends NotifyEvent
{
    public function __construct(
        string $rawLine,
        public readonly string $mailbox,
        public readonly string $rawEntries,
    ) {
        parent::__construct($rawLine);
    }
}
