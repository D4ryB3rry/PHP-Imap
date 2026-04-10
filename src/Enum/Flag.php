<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class Flag
{
    public const Seen = '\Seen';
    public const Answered = '\Answered';
    public const Flagged = '\Flagged';
    public const Deleted = '\Deleted';
    public const Draft = '\Draft';
    public const Recent = '\Recent';

    private const MAP = [
        '\Seen' => self::Seen,
        '\Answered' => self::Answered,
        '\Flagged' => self::Flagged,
        '\Deleted' => self::Deleted,
        '\Draft' => self::Draft,
        '\Recent' => self::Recent,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"Flag\"");
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
