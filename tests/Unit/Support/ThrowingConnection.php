<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use Throwable;

/**
 * ConnectionInterface stub whose every method throws — used to drive
 * LoggingConnection's catch/log/rethrow branches.
 */
final class ThrowingConnection implements ConnectionInterface
{
    public function __construct(
        private readonly Throwable $error = new ConnectionException('boom'),
    ) {
    }

    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void
    {
        throw $this->error;
    }

    public function readLine(): string
    {
        throw $this->error;
    }

    public function readBytes(int $count): string
    {
        throw $this->error;
    }

    public function write(string $data): void
    {
        throw $this->error;
    }

    public function enableTls(): void
    {
        throw $this->error;
    }

    public function close(): void
    {
        // close() must not throw — LoggingConnection::close() does not catch.
    }

    public function isConnected(): bool
    {
        return false;
    }
}
