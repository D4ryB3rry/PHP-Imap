<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class StatusAttribute
{
    public const Messages = 'MESSAGES';
    public const Recent = 'RECENT';
    public const UidNext = 'UIDNEXT';
    public const UidValidity = 'UIDVALIDITY';
    public const Unseen = 'UNSEEN';
    public const HighestModSeq = 'HIGHESTMODSEQ';
    public const Size = 'SIZE';

    private const MAP = [
        'MESSAGES' => self::Messages,
        'RECENT' => self::Recent,
        'UIDNEXT' => self::UidNext,
        'UIDVALIDITY' => self::UidValidity,
        'UNSEEN' => self::Unseen,
        'HIGHESTMODSEQ' => self::HighestModSeq,
        'SIZE' => self::Size,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"StatusAttribute\"");
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
