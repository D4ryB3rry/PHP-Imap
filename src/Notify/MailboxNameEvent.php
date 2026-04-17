<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 MailboxName: a `* LIST (...)` delivered for mailbox
 * create/delete/rename. Delete and the "gone" half of a rename surface as
 * an attribute list containing `\NonExistent`; a full rename is delivered
 * by the server as two consecutive events (old name with `\NonExistent`
 * followed by the new name). Correlating the pair is the handler's job —
 * the server does not guarantee atomic delivery.
 *
 * Heads-up: events are only pushed for mailboxes that match the filter
 * registered in NOTIFY SET. `selected` / `inboxes` filters will miss
 * renames elsewhere; use `personal` or `subtree ""` to see the whole tree.
 */
final class MailboxNameEvent extends NotifyEvent
{
    /**
     * @param string[] $attributes LIST attributes including any of \NonExistent, \Noselect, \Subscribed, special-use flags...
     */
    public function __construct(
        string $rawLine,
        public readonly string $mailbox,
        public readonly string $delimiter,
        public readonly array $attributes,
    ) {
        parent::__construct($rawLine);
    }

    public function isNonExistent(): bool
    {
        return in_array('\\NonExistent', $this->attributes, true);
    }
}
