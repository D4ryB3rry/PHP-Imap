<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\MailboxPath;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\MailboxPath
 */
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

    public function testDefaultDelimiterIsSlash(): void
    {
        $path = new MailboxPath('INBOX/Sent');

        self::assertSame('/', $path->delimiter);
        self::assertSame('Sent', $path->name());
    }

    public function testNameWithEmptyDelimiterReturnsFullPath(): void
    {
        $path = new MailboxPath('INBOX/Drafts', '');

        self::assertSame('INBOX/Drafts', $path->name());
    }

    public function testParentWithEmptyDelimiterReturnsNull(): void
    {
        $path = new MailboxPath('INBOX/Drafts', '');

        self::assertNull($path->parent());
    }

    public function testParentReturnsImmediateParentOnly(): void
    {
        $path = new MailboxPath('A/B/C/D');
        $parent = $path->parent();

        self::assertNotNull($parent);
        self::assertSame('A/B/C', $parent->path);
        self::assertSame('/', $parent->delimiter);
    }

    public function testParentChainTraversesToRoot(): void
    {
        $path = new MailboxPath('A/B/C');

        $first = $path->parent();
        self::assertNotNull($first);
        self::assertSame('A/B', $first->path);

        $second = $first->parent();
        self::assertNotNull($second);
        self::assertSame('A', $second->path);

        self::assertNull($second->parent());
    }

    public function testParentPreservesCustomDelimiter(): void
    {
        $parent = (new MailboxPath('Foo.Bar.Baz', '.'))->parent();

        self::assertNotNull($parent);
        self::assertSame('.', $parent->delimiter);
    }

    public function testChildPreservesDelimiter(): void
    {
        $child = (new MailboxPath('Foo', '.'))->child('Bar');

        self::assertSame('Foo.Bar', $child->path);
        self::assertSame('.', $child->delimiter);
    }

    public function testChildWithEmptyDelimiterConcatenatesDirectly(): void
    {
        $child = (new MailboxPath('INBOX', ''))->child('Drafts');

        self::assertSame('INBOXDrafts', $child->path);
    }

    public function testToStringReturnsPath(): void
    {
        $path = new MailboxPath('INBOX/Sub');

        self::assertSame('INBOX/Sub', (string) $path);
        self::assertSame('INBOX/Sub', $path->__toString());
    }

    public function testNameOfEmptyPath(): void
    {
        self::assertSame('', (new MailboxPath(''))->name());
    }

    public function testIsReadonlyAndExposesPublicProperties(): void
    {
        $path = new MailboxPath('INBOX/Drafts', '/');

        self::assertSame('INBOX/Drafts', $path->path);
        self::assertSame('/', $path->delimiter);
    }
}
