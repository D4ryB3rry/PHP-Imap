<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Collection;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\ValueObject\MailboxPath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Collection\FolderCollection
 * @uses \D4ry\ImapClient\ValueObject\MailboxPath
 * @uses \D4ry\ImapClient\Exception\ReadOnlyCollectionException
 */
final class FolderCollectionTest extends TestCase
{
    private function folder(string $name, string $path, ?string $use = null): FolderInterface
    {
        $stub = $this->createStub(FolderInterface::class);
        $stub->method('name')->willReturn($name);
        $stub->method('path')->willReturn(new MailboxPath($path));
        $stub->method('specialUse')->willReturn($use);
        return $stub;
    }

    public function testLazyLoadingHappensOnce(): void
    {
        $calls = 0;
        $collection = new FolderCollection(function () use (&$calls) {
            $calls++;
            return [$this->folder('INBOX', 'INBOX')];
        });

        self::assertSame(1, $collection->count());
        self::assertSame(1, $collection->count());
        self::assertSame(1, $calls);
    }

    public function testBySpecialUse(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $sent = $this->folder('Sent', 'Sent', SpecialUse::Sent);
        $collection = FolderCollection::fromArray([$inbox, $sent]);

        self::assertSame($sent, $collection->bySpecialUse(SpecialUse::Sent));
        self::assertNull($collection->bySpecialUse(SpecialUse::Trash));
    }

    public function testByName(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $drafts = $this->folder('Drafts', 'INBOX/Drafts');
        $collection = FolderCollection::fromArray([$inbox, $drafts]);

        self::assertSame($drafts, $collection->byName('Drafts'));
        self::assertNull($collection->byName('Nope'));
    }

    public function testByPath(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $drafts = $this->folder('Drafts', 'INBOX/Drafts');
        $collection = FolderCollection::fromArray([$inbox, $drafts]);

        self::assertSame($drafts, $collection->byPath('INBOX/Drafts'));
    }

    public function testIteration(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $drafts = $this->folder('Drafts', 'INBOX/Drafts');
        $sent = $this->folder('Sent', 'Sent', SpecialUse::Sent);
        $collection = FolderCollection::fromArray([$inbox, $drafts, $sent]);

        self::assertInstanceOf(\ArrayIterator::class, $collection->getIterator());

        $iterated = [];
        foreach ($collection as $key => $folder) {
            $iterated[$key] = $folder;
        }

        self::assertSame([0, 1, 2], array_keys($iterated));
        self::assertSame($inbox, $iterated[0]);
        self::assertSame($drafts, $iterated[1]);
        self::assertSame($sent, $iterated[2]);
    }

    public function testToArrayReturnsLoadedFolders(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $drafts = $this->folder('Drafts', 'INBOX/Drafts');
        $collection = FolderCollection::fromArray([$inbox, $drafts]);

        $array = $collection->toArray();

        self::assertSame([$inbox, $drafts], $array);
    }

    public function testOffsetExistsAndGet(): void
    {
        $inbox = $this->folder('INBOX', 'INBOX');
        $drafts = $this->folder('Drafts', 'INBOX/Drafts');
        $collection = FolderCollection::fromArray([$inbox, $drafts]);

        self::assertTrue(isset($collection[0]));
        self::assertTrue(isset($collection[1]));
        self::assertFalse(isset($collection[2]));
        self::assertSame($inbox, $collection[0]);
        self::assertSame($drafts, $collection[1]);
    }

    public function testOffsetSetThrows(): void
    {
        $collection = FolderCollection::fromArray([]);
        $this->expectException(ReadOnlyCollectionException::class);
        $collection[0] = $this->folder('X', 'X');
    }

    public function testOffsetUnsetThrows(): void
    {
        $collection = FolderCollection::fromArray([$this->folder('INBOX', 'INBOX')]);

        $this->expectException(ReadOnlyCollectionException::class);
        unset($collection[0]);
    }
}
