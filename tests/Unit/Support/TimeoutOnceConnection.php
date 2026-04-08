<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\TimeoutException;

/**
 * Connection decorator that throws a TimeoutException on the Nth readLine()
 * call and otherwise delegates to a wrapped FakeConnection. Used to exercise
 * the TimeoutException catch/continue branch in Mailbox::idle().
 */
final class TimeoutOnceConnection implements ConnectionInterface
{
    private int $callIndex = 0;

    public function __construct(
        public readonly FakeConnection $inner,
        public readonly int $throwOnCall = 1,
    ) {
    }

    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void
    {
        $this->inner->open($host, $port, $encryption, $timeout, $sslOptions);
    }

    public function setReadTimeout(float $timeout): void
    {
        $this->inner->setReadTimeout($timeout);
    }

    public function readLine(): string
    {
        $this->callIndex++;

        if ($this->callIndex === $this->throwOnCall) {
            throw new TimeoutException('simulated read timeout');
        }

        return $this->inner->readLine();
    }

    public function readBytes(int $count): string
    {
        return $this->inner->readBytes($count);
    }

    public function write(string $data): void
    {
        $this->inner->write($data);
    }

    public function enableTls(): void
    {
        $this->inner->enableTls();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }
}
