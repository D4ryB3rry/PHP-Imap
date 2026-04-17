<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

use D4ry\ImapClient\ValueObject\FlagSet;

/**
 * RFC 5465 MessageNew: a `* n FETCH (...)` delivered because a new message
 * arrived in a mailbox matched by the filter. The fetch payload may carry
 * whichever fetch-att items were requested in the NOTIFY SET MessageNew
 * clause; common ones are surfaced as typed properties, the rest remain in
 * $fetchData.
 */
final class MessageNewEvent extends NotifyEvent
{
    /**
     * @param array<string, mixed> $fetchData Raw FETCH map as produced by FetchResponseParser.
     */
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
        public readonly array $fetchData,
        public readonly ?FlagSet $flags = null,
    ) {
        parent::__construct($rawLine);
    }
}
