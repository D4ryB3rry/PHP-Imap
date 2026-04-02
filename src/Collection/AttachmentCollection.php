<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Collection;

use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;

/**
 * @implements \IteratorAggregate<int, AttachmentInterface>
 * @implements \ArrayAccess<int, AttachmentInterface>
 */
readonly class AttachmentCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /**
     * @param AttachmentInterface[] $attachments
     */
    public function __construct(
        private array $attachments = [],
    ) {
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->attachments);
    }

    public function count(): int
    {
        return count($this->attachments);
    }

    /**
     * @return AttachmentInterface[]
     */
    public function toArray(): array
    {
        return $this->attachments;
    }

    public function inline(): self
    {
        return new self(array_values(
            array_filter($this->attachments, fn(AttachmentInterface $a) => $a->isInline())
        ));
    }

    public function nonInline(): self
    {
        return new self(array_values(
            array_filter($this->attachments, fn(AttachmentInterface $a) => !$a->isInline())
        ));
    }

    public function isEmpty(): bool
    {
        return $this->attachments === [];
    }

    public function first(): ?AttachmentInterface
    {
        return $this->attachments[0] ?? null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attachments[$offset]);
    }

    public function offsetGet(mixed $offset): AttachmentInterface
    {
        return $this->attachments[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new ReadOnlyCollectionException();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new ReadOnlyCollectionException();
    }
}
