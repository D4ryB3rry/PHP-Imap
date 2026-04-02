<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Collection;

use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Enum\SpecialUse;

/**
 * @implements \IteratorAggregate<int, FolderInterface>
 */
class FolderCollection implements \IteratorAggregate, \Countable
{
    /** @var FolderInterface[]|null */
    private ?array $folders = null;

    /**
     * @param \Closure(): FolderInterface[] $loader
     */
    public function __construct(
        private readonly \Closure $loader,
    ) {
    }

    /**
     * @param FolderInterface[] $folders
     */
    public static function fromArray(array $folders): self
    {
        $collection = new self(fn() => $folders);
        $collection->folders = $folders;

        return $collection;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->load());
    }

    public function count(): int
    {
        return count($this->load());
    }

    /**
     * @return FolderInterface[]
     */
    public function toArray(): array
    {
        return $this->load();
    }

    public function bySpecialUse(SpecialUse $use): ?FolderInterface
    {
        foreach ($this->load() as $folder) {
            if ($folder->specialUse() === $use) {
                return $folder;
            }
        }

        return null;
    }

    public function byName(string $name): ?FolderInterface
    {
        foreach ($this->load() as $folder) {
            if ($folder->name() === $name) {
                return $folder;
            }
        }

        return null;
    }

    public function byPath(string $path): ?FolderInterface
    {
        return array_find($this->load(), fn($folder) => (string)$folder->path() === $path);

    }

    /**
     * @return FolderInterface[]
     */
    private function load(): array
    {
        if ($this->folders === null) {
            $this->folders = ($this->loader)();
        }

        return $this->folders;
    }
}
