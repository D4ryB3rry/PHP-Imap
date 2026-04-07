<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use D4ry\ImapClient\Support\Literal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Literal::class)]
final class LiteralTest extends TestCase
{
    public function testSizeReturnsByteLength(): void
    {
        self::assertSame(5, (new Literal('hello'))->size());
        self::assertSame(6, (new Literal('äöü'))->size()); // multi-byte (UTF-8: 2 bytes each)
    }

    public function testToImapStringSynchronizing(): void
    {
        $literal = new Literal('hello');

        self::assertSame("{5}\r\nhello", $literal->toImapString());
    }

    public function testToImapStringNonSynchronizing(): void
    {
        $literal = new Literal('hello', nonSynchronizing: true);

        self::assertSame("{5+}\r\nhello", $literal->toImapString());
    }
}
