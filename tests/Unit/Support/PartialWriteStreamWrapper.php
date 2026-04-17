<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

/**
 * userland stream wrapper that returns short writes from fwrite() so the
 * SocketConnection write-loop can be exercised without provoking real TCP
 * back-pressure (which is non-deterministic across platforms).
 *
 * Usage:
 *   PartialWriteStreamWrapper::reset([3, 4]); // 1st fwrite=3, 2nd=4, rest=full
 *   stream_wrapper_register('partial', PartialWriteStreamWrapper::class);
 *   $stream = fopen('partial://x', 'r+');
 *   ...
 *   stream_wrapper_unregister('partial');
 */
final class PartialWriteStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @var int[] */
    private static array $writeAllowances = [];

    private static string $writeLog = '';

    private static bool $eof = false;

    /** @var array<int,bool> */
    private static array $failNextWrite = [];

    /**
     * @param int[] $allowances per-call max bytes; once exhausted, full writes accepted
     */
    public static function reset(array $allowances = [], bool $failNext = false): void
    {
        self::$writeAllowances = $allowances;
        self::$writeLog = '';
        self::$eof = false;
        self::$failNextWrite = [$failNext];
    }

    public static function getWriteLog(): string
    {
        return self::$writeLog;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int|false
    {
        if (self::$failNextWrite !== [] && (self::$failNextWrite[0] ?? false)) {
            self::$failNextWrite = [false];

            return false;
        }

        $cap = strlen($data);

        if (self::$writeAllowances !== []) {
            $cap = min((int) array_shift(self::$writeAllowances), $cap);
        }

        self::$writeLog .= substr($data, 0, $cap);

        return $cap;
    }

    public function stream_eof(): bool
    {
        return self::$eof;
    }

    /**
     * @return array<int,int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
