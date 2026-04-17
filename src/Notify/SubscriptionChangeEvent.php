<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 SubscriptionChange: a `* LIST (...)` where the attributes
 * reflect (un)subscription. `\Subscribed` present → subscribed; absent →
 * unsubscribed. Some servers also emit LSUB in the same context.
 */
final class SubscriptionChangeEvent extends NotifyEvent
{
    /**
     * @param string[] $attributes
     */
    public function __construct(
        string $rawLine,
        public readonly string $mailbox,
        public readonly string $delimiter,
        public readonly array $attributes,
    ) {
        parent::__construct($rawLine);
    }

    public function isSubscribed(): bool
    {
        return in_array('\\Subscribed', $this->attributes, true);
    }
}
