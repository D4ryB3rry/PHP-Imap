<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Contract;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Idle\IdleHandlerInterface;
use D4ry\ImapClient\ValueObject\NamespaceInfo;

interface MailboxInterface
{
    public function folders(): FolderCollection;

    public function folder(string $path): FolderInterface;

    public function inbox(): FolderInterface;

    /**
     * @return Capability[]
     */
    public function capabilities(): array;

    public function hasCapability(Capability $capability): bool;

    /**
     * @param array<string, string>|null $clientParams
     * @return array<string, string>|null
     */
    public function id(?array $clientParams = null): ?array;

    public function namespace(): NamespaceInfo;

    public function idle(IdleHandlerInterface|callable $handler, float $timeout = 300): void;

    public function disconnect(): void;
}
