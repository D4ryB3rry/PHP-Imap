<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

use D4ry\ImapClient\Protocol\Command\CommandBuilder;

/**
 * RFC 5465 §5 filter-mailboxes production. Immutable, created through
 * static factories so the filter kind is unambiguous at the call site.
 */
final readonly class MailboxFilter
{
    public const KIND_SELECTED = 'selected';
    public const KIND_SELECTED_DELAYED = 'selected-delayed';
    public const KIND_INBOXES = 'inboxes';
    public const KIND_PERSONAL = 'personal';
    public const KIND_SUBSCRIBED = 'subscribed';
    public const KIND_SUBTREE = 'subtree';
    public const KIND_MAILBOXES = 'mailboxes';

    /**
     * @param string[] $mailboxes
     */
    private function __construct(
        public string $kind,
        public array $mailboxes = [],
    ) {
    }

    public static function selected(): self
    {
        return new self(self::KIND_SELECTED);
    }

    public static function selectedDelayed(): self
    {
        return new self(self::KIND_SELECTED_DELAYED);
    }

    public static function inboxes(): self
    {
        return new self(self::KIND_INBOXES);
    }

    public static function personal(): self
    {
        return new self(self::KIND_PERSONAL);
    }

    public static function subscribed(): self
    {
        return new self(self::KIND_SUBSCRIBED);
    }

    /**
     * @param string[] $mailboxes
     */
    public static function subtree(array $mailboxes): self
    {
        if ($mailboxes === []) {
            throw new \InvalidArgumentException('subtree filter requires at least one mailbox');
        }

        return new self(self::KIND_SUBTREE, array_values($mailboxes));
    }

    /**
     * @param string[] $mailboxes
     */
    public static function mailboxes(array $mailboxes): self
    {
        if ($mailboxes === []) {
            throw new \InvalidArgumentException('mailboxes filter requires at least one mailbox');
        }

        return new self(self::KIND_MAILBOXES, array_values($mailboxes));
    }

    public function toFilterToken(bool $utf8Enabled): string
    {
        return match ($this->kind) {
            self::KIND_SELECTED,
            self::KIND_SELECTED_DELAYED,
            self::KIND_INBOXES,
            self::KIND_PERSONAL,
            self::KIND_SUBSCRIBED => $this->kind,
            self::KIND_SUBTREE, self::KIND_MAILBOXES => sprintf(
                '%s %s',
                $this->kind,
                $this->encodeMailboxList($utf8Enabled),
            ),
        };
    }

    private function encodeMailboxList(bool $utf8Enabled): string
    {
        $encoded = array_map(
            fn(string $m): string => CommandBuilder::encodeMailboxName($m, $utf8Enabled),
            $this->mailboxes,
        );

        if (count($encoded) === 1) {
            return $encoded[0];
        }

        return '(' . implode(' ', $encoded) . ')';
    }
}
