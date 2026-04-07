<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol;

use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TagGenerator::class)]
#[CoversClass(Tag::class)]
final class TagGeneratorTest extends TestCase
{
    public function testTagsAreSequential(): void
    {
        $generator = new TagGenerator();

        self::assertSame('A0001', $generator->next()->value);
        self::assertSame('A0002', $generator->next()->value);
        self::assertSame('A0003', $generator->next()->value);
    }

    public function testIndependentInstancesDoNotShareCounter(): void
    {
        $a = new TagGenerator();
        $b = new TagGenerator();

        $a->next();
        $a->next();

        self::assertSame('A0001', $b->next()->value);
    }
}
