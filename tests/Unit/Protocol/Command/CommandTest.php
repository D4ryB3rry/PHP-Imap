<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Command;

use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\ValueObject\Tag
 */
final class CommandTest extends TestCase
{
    public function testCompileWithoutArguments(): void
    {
        $command = new Command(new Tag('A0001'), 'NOOP');

        self::assertSame("A0001 NOOP\r\n", $command->compile());
    }

    public function testCompileWithSingleArgument(): void
    {
        $command = new Command(new Tag('A0002'), 'SELECT', ['INBOX']);

        self::assertSame("A0002 SELECT INBOX\r\n", $command->compile());
    }

    public function testCompileWithMultipleArguments(): void
    {
        $command = new Command(
            new Tag('A0003'),
            'LOGIN',
            ['"user"', '"pass"'],
        );

        self::assertSame("A0003 LOGIN \"user\" \"pass\"\r\n", $command->compile());
    }

    public function testPropertiesAreExposed(): void
    {
        $tag = new Tag('A0099');
        $command = new Command($tag, 'FETCH', ['1', '(FLAGS)']);

        self::assertSame($tag, $command->tag);
        self::assertSame('FETCH', $command->name);
        self::assertSame(['1', '(FLAGS)'], $command->arguments);
    }
}
