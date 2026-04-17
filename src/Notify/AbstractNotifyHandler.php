<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

abstract class AbstractNotifyHandler implements NotifyHandlerInterface
{
    public function onMessageNew(MessageNewEvent $event): bool
    {
        return true;
    }

    public function onMessageExpunged(MessageExpungedEvent $event): bool
    {
        return true;
    }

    public function onFlagChange(FlagChangeEvent $event): bool
    {
        return true;
    }

    public function onMailboxName(MailboxNameEvent $event): bool
    {
        return true;
    }

    public function onSubscriptionChange(SubscriptionChangeEvent $event): bool
    {
        return true;
    }

    public function onAnnotationChange(AnnotationChangeEvent $event): bool
    {
        return true;
    }

    public function onMailboxMetadataChange(MailboxMetadataChangeEvent $event): bool
    {
        return true;
    }

    public function onServerMetadataChange(ServerMetadataChangeEvent $event): bool
    {
        return true;
    }

    public function onMailboxStatus(MailboxStatusEvent $event): bool
    {
        return true;
    }
}
