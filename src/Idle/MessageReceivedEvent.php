<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

/**
 * @infection-ignore-all
 */
class MessageReceivedEvent extends IdleEvent
{
    /**
     * @param string $rawLine        The raw IMAP line (e.g. "* 5 EXISTS")
     * @param int    $messageCount   Total number of messages now in the mailbox
     */
    public function __construct(
        string $rawLine,
        public readonly int $messageCount,
    ) {
        parent::__construct($rawLine);
    }

    /**
     * Sequence number of the newly arrived message.
     *
     * In IMAP, EXISTS always reports the new total count,
     * and the new message is always assigned the highest sequence number.
     */
    public int $sequenceNumber {
        get => $this->messageCount;
    }
}
