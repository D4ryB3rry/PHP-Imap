<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class SpecialUse
{
    public const All = '\All';
    public const Archive = '\Archive';
    public const Drafts = '\Drafts';
    public const Flagged = '\Flagged';
    public const Junk = '\Junk';
    public const Sent = '\Sent';
    public const Trash = '\Trash';
    public const Inbox = 'INBOX';

    private const MAP = [
        '\All' => self::All,
        '\Archive' => self::Archive,
        '\Drafts' => self::Drafts,
        '\Flagged' => self::Flagged,
        '\Junk' => self::Junk,
        '\Sent' => self::Sent,
        '\Trash' => self::Trash,
        'INBOX' => self::Inbox,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"SpecialUse\"");
    }

    public static function tryFrom(string $value): ?string
    {
        return self::MAP[$value] ?? null;
    }

    /** @return string[] */
    public static function cases(): array
    {
        return array_values(self::MAP);
    }
}
