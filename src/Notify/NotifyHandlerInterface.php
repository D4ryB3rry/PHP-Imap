<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * All handler methods return a bool: true to keep the active
 * {@see \D4ry\ImapClient\Contract\MailboxInterface::listenForNotifications()}
 * loop running, false to break out. The return value is ignored by the
 * passive dispatch path because there is no loop to stop there.
 */
interface NotifyHandlerInterface
{
    public function onMessageNew(MessageNewEvent $event): bool;

    public function onMessageExpunged(MessageExpungedEvent $event): bool;

    public function onFlagChange(FlagChangeEvent $event): bool;

    public function onMailboxName(MailboxNameEvent $event): bool;

    public function onSubscriptionChange(SubscriptionChangeEvent $event): bool;

    public function onAnnotationChange(AnnotationChangeEvent $event): bool;

    public function onMailboxMetadataChange(MailboxMetadataChangeEvent $event): bool;

    public function onServerMetadataChange(ServerMetadataChangeEvent $event): bool;

    public function onMailboxStatus(MailboxStatusEvent $event): bool;
}
