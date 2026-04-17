<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Contract;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Collection\MessageCollection;
use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Notify\NotifyEventType;
use D4ry\ImapClient\Notify\NotifyHandlerInterface;
use D4ry\ImapClient\Search\Contract\SearchCriteriaInterface;
use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\MailboxStatus;
use D4ry\ImapClient\ValueObject\Uid;

interface FolderInterface
{
    public function path(): MailboxPath;

    public function name(): string;

    public function status(): MailboxStatus;

    public function specialUse(): ?SpecialUse;

    public function messages(Flag|SearchCriteriaInterface|null $criteria = null): MessageCollection;

    public function messagesRange(int $from, int $to, bool $useUid = false, bool $withBodyStructure = false): MessageCollection;

    public function message(Uid $uid): MessageInterface;

    public function search(SearchCriteriaInterface $criteria): SearchResult;

    public function select(): self;

    public function examine(): self;

    public function create(): self;

    public function delete(): void;

    public function rename(string $newName): self;

    public function subscribe(): self;

    public function unsubscribe(): self;

    public function expunge(): void;

    /**
     * @param Uid[] $uids
     */
    public function moveMessages(array $uids, FolderInterface|string $destination): void;

    /**
     * @param Uid[] $uids
     */
    public function copyMessages(array $uids, FolderInterface|string $destination): void;

    public function children(): FolderCollection;

    public function append(string $rawMessage, array $flags = [], ?\DateTimeInterface $internalDate = null): ?Uid;

    /**
     * Subscribe to NOTIFY events for this folder and drain them until the
     * handler returns false or the timeout expires. Registers
     * `NOTIFY SET (mailboxes|subtree <this path> (events))` and tears the
     * subscription down with `NOTIFY NONE` on return.
     *
     * @param NotifyEventType[] $events Defaults to MessageNew + MessageExpunge + FlagChange.
     */
    public function listen(
        NotifyHandlerInterface|callable $handler,
        float $timeout = 300,
        array $events = [],
        bool $includeSubtree = false,
    ): void;
}
