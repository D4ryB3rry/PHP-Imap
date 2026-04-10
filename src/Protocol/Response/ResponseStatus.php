<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

final class ResponseStatus
{
    public const Ok = 'OK';
    public const No = 'NO';
    public const Bad = 'BAD';
    public const PreAuth = 'PREAUTH';
    public const Bye = 'BYE';

    private const MAP = [
        'OK' => self::Ok,
        'NO' => self::No,
        'BAD' => self::Bad,
        'PREAUTH' => self::PreAuth,
        'BYE' => self::Bye,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"ResponseStatus\"");
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
