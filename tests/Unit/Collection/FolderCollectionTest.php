<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Collection;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\ReadOnlyCollectionException;
use D4ry\ImapClient\ValueObject\MailboxPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FolderCollection::class)]
#[UsesClass(MailboxPath::class)]
#[UsesClass(ReadOnlyCollectionException::class)]
final class FolderCollectionTest extends TestCase
{
    private function folder(string $name, string $path, ?SpecialUse $use = null): FolderInterface
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

    public function testOffsetSetThrows(): void
    {
        $collection = FolderCollection::fromArray([]);
        $this->expectException(ReadOnlyCollectionException::class);
        $collection[0] = $this->folder('X', 'X');
    }
}
