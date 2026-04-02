<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

interface IdleHandlerInterface
{
    /**
     * New message(s) arrived. $event->messageCount is the new total.
     * Return false to stop IDLE.
     */
    public function onMessageReceived(MessageReceivedEvent $event): bool;

    /**
     * A message was expunged. $event->sequenceNumber is the removed sequence.
     * Return false to stop IDLE.
     */
    public function onMessageExpunged(MessageExpungedEvent $event): bool;

    /**
     * Flags changed on a message. $event->sequenceNumber and $event->flags.
     * Return false to stop IDLE.
     */
    public function onFlagsChanged(FlagsChangedEvent $event): bool;

    /**
     * Recent count changed.
     * Return false to stop IDLE.
     */
    public function onRecentCount(RecentCountEvent $event): bool;

    /**
     * Server keepalive / status message.
     * Return false to stop IDLE.
     */
    public function onHeartbeat(IdleHeartbeatEvent $event): bool;
}
