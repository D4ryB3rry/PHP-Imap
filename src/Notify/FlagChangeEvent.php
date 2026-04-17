<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

use D4ry\ImapClient\ValueObject\FlagSet;

/**
 * RFC 5465 FlagChange: a `* n FETCH (FLAGS (...))` delivered because a
 * message's flag set changed.
 */
final class FlagChangeEvent extends NotifyEvent
{
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
        public readonly FlagSet $flags,
    ) {
        parent::__construct($rawLine);
    }
}
