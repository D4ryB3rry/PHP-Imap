<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class Encryption
{
    public const Tls = 'tls';
    public const StartTls = 'starttls';
    public const None = 'none';

    private const MAP = [
        'tls' => self::Tls,
        'starttls' => self::StartTls,
        'none' => self::None,
    ];

    private const NAME_MAP = [
        'tls' => 'Tls',
        'starttls' => 'StartTls',
        'none' => 'None',
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"Encryption\"");
    }

    public static function tryFrom(string $value): ?string
    {
        return self::MAP[$value] ?? null;
    }

    public static function nameOf(string $value): string
    {
        return self::NAME_MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"Encryption\"");
    }

    /** @return string[] */
    public static function cases(): array
    {
        return array_values(self::MAP);
    }
}
