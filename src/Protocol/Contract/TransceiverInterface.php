<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Contract;

use D4ry\ImapClient\Protocol\Response\Response;

interface TransceiverInterface
{
    public function command(string $name, string ...$args): Response;

    public function commandRaw(string $rawLine): Response;

    /**
     * @return string[]
     */
    public function capabilities(): array;

    public function hasCapability(string $capability): bool;

    public function requireCapability(string $capability): void;

    public function sendContinuationData(string $data): void;

    public function isUtf8Enabled(): bool;
}
