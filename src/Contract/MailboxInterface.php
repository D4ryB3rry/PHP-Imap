<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Contract;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Idle\IdleHandlerInterface;
use D4ry\ImapClient\Notify\EventGroup;
use D4ry\ImapClient\Notify\NotifyEventType;
use D4ry\ImapClient\Notify\NotifyHandlerInterface;
use D4ry\ImapClient\ValueObject\NamespaceInfo;

interface MailboxInterface
{
    public function folders(): FolderCollection;

    /**
     * Like folders(), but each Folder comes with pre-cached MailboxStatus.
     * Uses LIST-STATUS (RFC 5819) when available, falls back to individual
     * STATUS round-trips per folder.
     */
    public function foldersWithStatus(): FolderCollection;

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

    /**
     * @param EventGroup[] $groups
     */
    public function notify(array $groups, bool $includeStatus = false): void;

    public function notifyNone(): void;

    /**
     * Register (or clear with null) a handler that receives NOTIFY events
     * passively — i.e. any server-pushed untagged response arriving in the
     * reply of a subsequent command is classified and dispatched without a
     * dedicated loop. Separate from {@see listenForNotifications()} which
     * actively pumps the socket.
     */
    public function setNotifyHandler(NotifyHandlerInterface|callable|null $handler): void;

    /**
     * Pump untagged responses arriving from a previously-registered NOTIFY
     * subscription and dispatch them to the handler. Returns when the
     * timeout expires or the handler returns false.
     */
    public function listenForNotifications(
        NotifyHandlerInterface|callable $handler,
        float $timeout = 300,
    ): void;

    /**
     * Convenience: register a NOTIFY subscription for the given folders and
     * pump their events until timeout or handler returns false. On exit the
     * subscription is torn down with NOTIFY NONE.
     *
     * @param list<\D4ry\ImapClient\Contract\FolderInterface|string> $folders
     * @param NotifyEventType[] $events Defaults to MessageNew + MessageExpunge + FlagChange.
     */
    public function listenToFolders(
        array $folders,
        NotifyHandlerInterface|callable $handler,
        float $timeout = 300,
        array $events = [],
        bool $includeSubtree = false,
    ): void;

    public function disconnect(): void;
}
