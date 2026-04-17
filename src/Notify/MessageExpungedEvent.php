<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 MessageExpunge: a `* n EXPUNGE` notification. On servers that
 * advertise QRESYNC, VANISHED untagged responses are normalised into a
 * sequence of MessageExpungedEvent instances, one per removed UID.
 */
final class MessageExpungedEvent extends NotifyEvent
{
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
    ) {
        parent::__construct($rawLine);
    }
}
