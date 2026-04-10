<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class SortCriterion
{
    public const Date = 'DATE';
    public const Arrival = 'ARRIVAL';
    public const From = 'FROM';
    public const Subject = 'SUBJECT';
    public const Size = 'SIZE';
    public const Cc = 'CC';
    public const To = 'TO';

    private const MAP = [
        'DATE' => self::Date,
        'ARRIVAL' => self::Arrival,
        'FROM' => self::From,
        'SUBJECT' => self::Subject,
        'SIZE' => self::Size,
        'CC' => self::Cc,
        'TO' => self::To,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"SortCriterion\"");
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
