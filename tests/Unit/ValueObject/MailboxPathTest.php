<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\MailboxPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailboxPath::class)]
final class MailboxPathTest extends TestCase
{
    public function testNameReturnsLastSegment(): void
    {
        self::assertSame('Drafts', (new MailboxPath('INBOX/Drafts'))->name());
        self::assertSame('Bar', (new MailboxPath('Foo.Bar', '.'))->name());
        self::assertSame('INBOX', (new MailboxPath('INBOX'))->name());
    }

    public function testParentReturnsNullForRoot(): void
    {
        self::assertNull((new MailboxPath('INBOX'))->parent());
    }

    public function testParentReturnsParentPath(): void
    {
        $parent = (new MailboxPath('A/B/C'))->parent();

        self::assertNotNull($parent);
        self::assertSame('A/B', $parent->path);
    }

    public function testChildAppendsName(): void
    {
        $child = (new MailboxPath('INBOX'))->child('Drafts');

        self::assertSame('INBOX/Drafts', (string) $child);
    }

    public function testCustomDelimiter(): void
    {
        $path = new MailboxPath('Foo.Bar.Baz', '.');

        self::assertSame('Foo.Bar', (string) $path->parent());
        self::assertSame('Foo.Bar.Baz.Quux', (string) $path->child('Quux'));
    }
}
