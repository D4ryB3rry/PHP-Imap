<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Idle;

abstract class AbstractIdleHandler implements IdleHandlerInterface
{
    public function onMessageReceived(MessageReceivedEvent $event): bool
    {
        return true;
    }

    public function onMessageExpunged(MessageExpungedEvent $event): bool
    {
        return true;
    }

    public function onFlagsChanged(FlagsChangedEvent $event): bool
    {
        return true;
    }

    public function onRecentCount(RecentCountEvent $event): bool
    {
        return true;
    }

    public function onHeartbeat(IdleHeartbeatEvent $event): bool
    {
        return true;
    }
}
