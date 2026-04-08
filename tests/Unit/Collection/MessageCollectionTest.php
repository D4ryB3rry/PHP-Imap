<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Collection;

use D4ry\ImapClient\Collection\MessageCollection;
use D4ry\ImapClient\Contract\MessageInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageCollection::class)]
#[UsesClass(ReadOnlyCollectionException::class)]
final class MessageCollectionTest extends TestCase
{
    public function testLazyLoaderInvokedOnce(): void
    {
        $calls = 0;
        $messages = [$this->createStub(MessageInterface::class), $this->createStub(MessageInterface::class)];
        $collection = new MessageCollection(function () use (&$calls, $messages) {
            $calls++;
            return $messages;
        });

        self::assertSame(2, $collection->count());
        self::assertSame(2, $collection->count());
        self::assertNotNull($collection->first());
        $collection->toArray();

        self::assertSame(1, $calls);
    }

    public function testFromArrayBypassesLoader(): void
    {
        $msg = $this->createStub(MessageInterface::class);
        $collection = MessageCollection::fromArray([$msg]);

        self::assertSame(1, $collection->count());
        self::assertSame($msg, $collection[0]);
        self::assertFalse($collection->isEmpty());
    }

    public function testEmptyCollection(): void
    {
        $collection = new MessageCollection(fn () => []);

        self::assertSame(0, $collection->count());
        self::assertNull($collection->first());
        self::assertTrue($collection->isEmpty());
    }

    public function testIterable(): void
    {
        $msgs = [$this->createStub(MessageInterface::class), $this->createStub(MessageInterface::class)];
        $collection = MessageCollection::fromArray($msgs);

        $iterated = [];
        foreach ($collection as $m) {
            $iterated[] = $m;
        }
        self::assertSame($msgs, $iterated);
    }

    public function testOffsetExistsAndGet(): void
    {
        $a = $this->createStub(MessageInterface::class);
        $b = $this->createStub(MessageInterface::class);
        $collection = MessageCollection::fromArray([$a, $b]);

        self::assertTrue(isset($collection[0]));
        self::assertTrue(isset($collection[1]));
        self::assertFalse(isset($collection[2]));
        self::assertSame($a, $collection[0]);
        self::assertSame($b, $collection[1]);
    }

    public function testIteratorStreamsFromGeneratorAndCachesResult(): void
    {
        $a = $this->createStub(MessageInterface::class);
        $b = $this->createStub(MessageInterface::class);
        $calls = 0;
        $collection = new MessageCollection(function () use (&$calls, $a, $b) {
            $calls++;
            yield $a;
            yield $b;
        });

        $iterated = [];
        foreach ($collection as $m) {
            $iterated[] = $m;
        }
        self::assertSame([$a, $b], $iterated);

        // Subsequent operations must use the cached buffer, not invoke the loader again.
        self::assertSame(2, $collection->count());
        self::assertSame([$a, $b], $collection->toArray());
        self::assertSame(1, $calls);

        // After caching, getIterator returns an ArrayIterator over the buffer.
        $second = [];
        foreach ($collection as $m) {
            $second[] = $m;
        }
        self::assertSame([$a, $b], $second);
        self::assertSame(1, $calls);
    }

    public function testIteratorWithArrayLoaderCachesAndYields(): void
    {
        $a = $this->createStub(MessageInterface::class);
        $messages = [$a];
        $calls = 0;
        $collection = new MessageCollection(function () use (&$calls, $messages) {
            $calls++;
            return $messages;
        });

        $iterated = [];
        foreach ($collection as $m) {
            $iterated[] = $m;
        }
        self::assertSame($messages, $iterated);
        self::assertSame(1, $collection->count());
        self::assertSame(1, $calls);
    }

    public function testLoadConvertsIteratorWhenAccessedBeforeIteration(): void
    {
        $a = $this->createStub(MessageInterface::class);
        $b = $this->createStub(MessageInterface::class);
        $collection = new MessageCollection(function () use ($a, $b) {
            yield $a;
            yield $b;
        });

        // count() calls load() before any foreach — exercises the iterator_to_array branch.
        self::assertSame(2, $collection->count());
        self::assertSame([$a, $b], $collection->toArray());
    }

    public function testOffsetSetThrows(): void
    {
        $collection = MessageCollection::fromArray([]);

        $this->expectException(ReadOnlyCollectionException::class);
        $collection[0] = $this->createStub(MessageInterface::class);
    }

    public function testOffsetUnsetThrows(): void
    {
        $collection = MessageCollection::fromArray([$this->createStub(MessageInterface::class)]);

        $this->expectException(ReadOnlyCollectionException::class);
        unset($collection[0]);
    }
}
