<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Contract;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Protocol\Response\Response;

interface TransceiverInterface
{
    public function command(string $name, string ...$args): Response;

    public function commandRaw(string $rawLine): Response;

    /**
     * @return Capability[]
     */
    public function capabilities(): array;

    public function hasCapability(Capability $capability): bool;

    public function requireCapability(Capability $capability): void;

    public function sendContinuationData(string $data): void;

    public null|string $selectedMailbox {
        get;
        set;
    }

    public function isUtf8Enabled(): bool;
}
