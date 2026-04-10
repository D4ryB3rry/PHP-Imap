<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class ContentTransferEncoding
{
    public const SevenBit = '7bit';
    public const EightBit = '8bit';
    public const Binary = 'binary';
    public const QuotedPrintable = 'quoted-printable';
    public const Base64 = 'base64';

    private const MAP = [
        '7bit' => self::SevenBit,
        '8bit' => self::EightBit,
        'binary' => self::Binary,
        'quoted-printable' => self::QuotedPrintable,
        'base64' => self::Base64,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"ContentTransferEncoding\"");
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
