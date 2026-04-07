<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Enum;

use D4ry\ImapClient\Enum\Flag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Flag::class)]
final class FlagTest extends TestCase
{
    public function testImapStringMatchesValue(): void
    {
        self::assertSame('\\Seen', Flag::Seen->imapString());
        self::assertSame('\\Answered', Flag::Answered->imapString());
        self::assertSame('\\Flagged', Flag::Flagged->imapString());
        self::assertSame('\\Deleted', Flag::Deleted->imapString());
        self::assertSame('\\Draft', Flag::Draft->imapString());
        self::assertSame('\\Recent', Flag::Recent->imapString());
    }
}
