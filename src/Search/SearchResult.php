<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Search;

use D4ry\ImapClient\ValueObject\Uid;

readonly class SearchResult
{
    /**
     * @param Uid[] $uids
     */
    public function __construct(
        public array $uids,
        public ?int $highestModSeq = null,
    ) {
    }

    public function count(): int
    {
        return count($this->uids);
    }

    public function isEmpty(): bool
    {
        return $this->uids === [];
    }

    /**
     * @return int[]
     */
    public function uidValues(): array
    {
        return array_map(fn(Uid $uid) => $uid->value, $this->uids);
    }
}
