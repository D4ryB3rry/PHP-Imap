<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Collection;

use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;

/**
 * @implements \IteratorAggregate<int, FolderInterface>
 * @implements \ArrayAccess<int, FolderInterface>
 */
class FolderCollection implements \IteratorAggregate, \Countable, \ArrayAccess
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
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->load()[$offset]);
    }

    public function offsetGet(mixed $offset): FolderInterface
    {
        return $this->load()[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new ReadOnlyCollectionException();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new ReadOnlyCollectionException();
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
