<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 ServerMetadataChange: a `* METADATA "" (entry value ...)`
 * untagged response (empty mailbox argument = server scope).
 */
final class ServerMetadataChangeEvent extends NotifyEvent
{
    public function __construct(
        string $rawLine,
        public readonly string $rawEntries,
    ) {
        parent::__construct($rawLine);
    }
}
