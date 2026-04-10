<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Collection;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Collection\AttachmentCollection
 * @uses \D4ry\ImapClient\Exception\ReadOnlyCollectionException
 */
final class AttachmentCollectionTest extends TestCase
{
    private function makeAttachment(bool $inline): AttachmentInterface
    {
        $stub = $this->createStub(AttachmentInterface::class);
        $stub->method('isInline')->willReturn($inline);
        return $stub;
    }

    public function testCountAndIteration(): void
    {
        $a = $this->makeAttachment(false);
        $b = $this->makeAttachment(true);
        $collection = new AttachmentCollection([$a, $b]);

        self::assertSame(2, $collection->count());
        self::assertFalse($collection->isEmpty());
        self::assertSame($a, $collection->first());
        self::assertSame([$a, $b], iterator_to_array($collection));
    }

    public function testInlineFilter(): void
    {
        // To kill the UnwrapArrayValues mutant on BOTH inline() and
        // nonInline(), the surviving element must end up at a non-zero
        // index in the post-filter result. We need TWO orderings — one
        // where the inline survives at a non-zero index (regular first),
        // and one where the non-inline survives at a non-zero index
        // (inline first).
        $regular = $this->makeAttachment(false);
        $inline  = $this->makeAttachment(true);

        // Order #1: regular first, inline at index 1 — kills inline()'s
        // array_values wrapper.
        $a = new AttachmentCollection([$regular, $inline]);
        self::assertSame([0 => $inline], $a->inline()->toArray());

        // Order #2: inline first, regular at index 1 — kills nonInline()'s
        // array_values wrapper.
        $b = new AttachmentCollection([$inline, $regular]);
        self::assertSame([0 => $regular], $b->nonInline()->toArray());
    }

    public function testEmptyCollection(): void
    {
        $collection = new AttachmentCollection();

        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->first());
        self::assertSame(0, $collection->count());
    }

    public function testOffsetExistsAndGet(): void
    {
        $a = $this->makeAttachment(false);
        $b = $this->makeAttachment(true);
        $collection = new AttachmentCollection([$a, $b]);

        self::assertTrue(isset($collection[0]));
        self::assertTrue(isset($collection[1]));
        self::assertFalse(isset($collection[2]));
        self::assertSame($a, $collection[0]);
        self::assertSame($b, $collection[1]);
    }

    public function testOffsetSetThrows(): void
    {
        $collection = new AttachmentCollection();

        $this->expectException(ReadOnlyCollectionException::class);
        $collection[0] = $this->makeAttachment(false);
    }

    public function testOffsetUnsetThrows(): void
    {
        $collection = new AttachmentCollection([$this->makeAttachment(false)]);

        $this->expectException(ReadOnlyCollectionException::class);
        unset($collection[0]);
    }
}
