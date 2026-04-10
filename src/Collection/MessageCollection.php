<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Collection;

use D4ry\ImapClient\Contract\MessageInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;

/**
 * @implements \IteratorAggregate<int, MessageInterface>
 * @implements \ArrayAccess<int, MessageInterface>
 */
class MessageCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** @var MessageInterface[]|null */
    private ?array $messages = null;

    /**
     * @param \Closure(): (MessageInterface[]|iterable<MessageInterface>) $loader
     *
     * The loader may return either a fully materialized array or any
     * iterable (typically a Generator) that yields messages as they arrive
     * from the server. Generator-backed loaders allow `foreach` consumers to
     * begin processing messages while the rest of the response is still in
     * flight on the wire — see {@see Folder::messages()} for the streaming
     * UID FETCH path.
     */
    public function __construct(
        private \Closure $loader,
    ) {
    }

    /**
     * @param MessageInterface[] $messages
     */
    public static function fromArray(array $messages): self
    {
        $collection = new self(fn() => $messages);
        $collection->messages = $messages;

        return $collection;
    }

    public function getIterator(): \Traversable
    {
        if ($this->messages !== null) {
            return new \ArrayIterator($this->messages);
        }

        return $this->streamMessages();
    }

    /**
     * @return \Generator<int, MessageInterface>
     */
    private function streamMessages(): \Generator
    {
        $result = ($this->loader)();

        if (is_array($result)) {
            $this->messages = $result;
            foreach ($result as $message) {
                yield $message;
            }

            return;
        }

        $buffer = [];
        foreach ($result as $message) {
            $buffer[] = $message;
            yield $message;
        }

        $this->messages = $buffer;
    }

    public function count(): int
    {
        return count($this->load());
    }

    public function first(): ?MessageInterface
    {
        $messages = $this->load();

        return $messages !== [] ? $messages[0] : null;
    }

    /**
     * @return MessageInterface[]
     */
    public function toArray(): array
    {
        return $this->load();
    }

    public function isEmpty(): bool
    {
        return $this->load() === [];
    }

    /**
     * @return MessageInterface[]
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->load()[$offset]);
    }

    public function offsetGet(mixed $offset): MessageInterface
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
     * @return MessageInterface[]
     */
    private function load(): array
    {
        if ($this->messages !== null) {
            return $this->messages;
        }

        $result = ($this->loader)();

        if (is_array($result)) {
            $this->messages = $result;
        } else {
            $this->messages = iterator_to_array($result, false);
        }

        return $this->messages;
    }
}
