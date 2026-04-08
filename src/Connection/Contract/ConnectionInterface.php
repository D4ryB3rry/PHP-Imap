<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection\Contract;

use D4ry\ImapClient\Enum\Encryption;

interface ConnectionInterface
{
    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void;

    /**
     * Override the read timeout on the underlying stream after open().
     * Used by the handshake to enforce a shorter window on the server greeting
     * so that an encryption mismatch fails fast instead of blocking for the
     * full connection timeout.
     */
    public function setReadTimeout(float $timeout): void;

    public function readLine(): string;

    public function readBytes(int $count): string;

    public function write(string $data): void;

    public function enableTls(): void;

    public function close(): void;

    public function isConnected(): bool;
}
