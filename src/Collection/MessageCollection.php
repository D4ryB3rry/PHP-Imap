<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Collection;

use D4ry\ImapClient\Contract\MessageInterface;

/**
 * @implements \IteratorAggregate<int, MessageInterface>
 */
class MessageCollection implements \IteratorAggregate, \Countable
{
    /** @var MessageInterface[]|null */
    private ?array $messages = null;

    /**
     * @param \Closure(): MessageInterface[] $loader
     */
    public function __construct(
        private readonly \Closure $loader,
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
        return new \ArrayIterator($this->load());
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
    private function load(): array
    {
        if ($this->messages === null) {
            $this->messages = ($this->loader)();
        }

        return $this->messages;
    }
}
