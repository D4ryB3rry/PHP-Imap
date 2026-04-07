<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Collection;

use D4ry\ImapClient\Collection\AttachmentCollection;
use D4ry\ImapClient\Contract\AttachmentInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttachmentCollection::class)]
#[UsesClass(ReadOnlyCollectionException::class)]
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
        $regular = $this->makeAttachment(false);
        $inline = $this->makeAttachment(true);
        $collection = new AttachmentCollection([$regular, $inline]);

        self::assertSame([$inline], $collection->inline()->toArray());
        self::assertSame([$regular], $collection->nonInline()->toArray());
    }

    public function testEmptyCollection(): void
    {
        $collection = new AttachmentCollection();

        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->first());
        self::assertSame(0, $collection->count());
    }

    public function testOffsetSetThrows(): void
    {
        $collection = new AttachmentCollection();

        $this->expectException(ReadOnlyCollectionException::class);
        $collection[0] = $this->makeAttachment(false);
    }
}
