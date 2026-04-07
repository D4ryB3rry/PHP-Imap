<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection\Contract;

use D4ry\ImapClient\Enum\Encryption;

interface ConnectionInterface
{
    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void;

    public function readLine(): string;

    public function readBytes(int $count): string;

    public function write(string $data): void;

    public function enableTls(): void;

    public function close(): void;

    public function isConnected(): bool;
}
