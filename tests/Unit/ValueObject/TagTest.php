<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tag::class)]
final class TagTest extends TestCase
{
    public function testStoresValue(): void
    {
        $tag = new Tag('A001');

        self::assertSame('A001', $tag->value);
    }

    public function testToStringReturnsValue(): void
    {
        self::assertSame('A001', (string) new Tag('A001'));
        self::assertSame('TAG42', (string) new Tag('TAG42'));
    }

    public function testEmptyValueIsAllowed(): void
    {
        $tag = new Tag('');

        self::assertSame('', $tag->value);
        self::assertSame('', (string) $tag);
    }

    public function testIsReadonly(): void
    {
        self::assertTrue(new \ReflectionClass(Tag::class)->isReadOnly());
    }
}
