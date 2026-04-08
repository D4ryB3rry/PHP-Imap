<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use RuntimeException;

/**
 * In-memory ConnectionInterface used to drive a real Transceiver from unit tests.
 *
 * - write() captures every byte sent by the client into $writes (in order).
 * - readLine() pops the next pre-scripted server line from the read queue.
 *
 * Lines passed to queueLines() should NOT contain a trailing CRLF — the helper
 * appends "\r\n" itself, matching the framing the real ResponseParser expects.
 */
final class FakeConnection implements ConnectionInterface
{
    /** @var string[] */
    public array $writes = [];

    /** @var string[] */
    private array $readQueue = [];

    /** @var string[] */
    private array $byteQueue = [];

    private bool $connected = true;

    public bool $tlsEnabled = false;

    public ?float $lastReadTimeout = null;

    public function queueLines(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->readQueue[] = $line . "\r\n";
        }
    }

    public function queueBytes(string ...$chunks): void
    {
        foreach ($chunks as $chunk) {
            $this->byteQueue[] = $chunk;
        }
    }

    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void
    {
        $this->connected = true;
    }

    public function setReadTimeout(float $timeout): void
    {
        $this->lastReadTimeout = $timeout;
    }

    public function readLine(): string
    {
        if ($this->readQueue === []) {
            throw new RuntimeException('FakeConnection read queue is empty — client read more lines than the test scripted.');
        }

        return array_shift($this->readQueue);
    }

    public function readBytes(int $count): string
    {
        if ($this->byteQueue === []) {
            return '';
        }

        return array_shift($this->byteQueue);
    }

    public function streamBytesTo($sink, int $count): void
    {
        $data = $this->readBytes($count);
        fwrite($sink, $data);
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    public function enableTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}
