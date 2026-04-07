<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Command;

use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandBuilder::class)]
#[CoversClass(Command::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
final class CommandBuilderTest extends TestCase
{
    public function testQuoteStringPlainAtomNotQuoted(): void
    {
        self::assertSame('INBOX', CommandBuilder::quoteString('INBOX'));
    }

    public function testQuoteStringEmptyIsQuoted(): void
    {
        self::assertSame('""', CommandBuilder::quoteString(''));
    }

    public function testQuoteStringWithSpacesIsQuoted(): void
    {
        self::assertSame('"with space"', CommandBuilder::quoteString('with space'));
    }

    public function testQuoteStringEscapesSpecials(): void
    {
        self::assertSame('"a\\\\b\\"c"', CommandBuilder::quoteString('a\\b"c'));
    }

    public function testEncodeMailboxNameUtf8ModeAscii(): void
    {
        // Pure ASCII atoms are returned unquoted by quoteString
        self::assertSame('Posteingang', CommandBuilder::encodeMailboxName('Posteingang', utf8Enabled: true));
    }

    public function testEncodeMailboxNameUtf8ModeWithSpace(): void
    {
        self::assertSame('"My Folder"', CommandBuilder::encodeMailboxName('My Folder', utf8Enabled: true));
    }

    public function testEncodeMailboxNameAsciiPassesThrough(): void
    {
        self::assertSame('INBOX', CommandBuilder::encodeMailboxName('INBOX'));
    }

    public function testEncodeMailboxNameUsesModifiedUtf7ForNonAscii(): void
    {
        // "Entwürfe" → contains 'ü' (U+00FC) → modified UTF-7
        $encoded = CommandBuilder::encodeMailboxName('Entwürfe');
        // Decoding round-trip
        $decoded = CommandBuilder::decodeMailboxName(trim($encoded, '"'));

        self::assertSame('Entwürfe', $decoded);
    }

    #[DataProvider('mailboxNameProvider')]
    public function testRoundTripModifiedUtf7(string $name): void
    {
        $encoded = CommandBuilder::utf8ToModifiedUtf7($name);

        self::assertSame($name, CommandBuilder::modifiedUtf7ToUtf8($encoded));
    }

    public static function mailboxNameProvider(): iterable
    {
        yield 'ascii inbox' => ['INBOX'];
        yield 'ascii sent' => ['Sent'];
        yield 'german umlaut' => ['Entwürfe'];
        yield 'cyrillic' => ['Папка'];
        yield 'japanese' => ['メール'];
        yield 'mixed ascii + nonascii' => ['Foo Ümlaut Bar'];
    }

    public function testModifiedUtf7DecodesEscapedAmpersand(): void
    {
        // &- in modified UTF-7 is the literal '&' character
        self::assertSame('foo & bar', CommandBuilder::modifiedUtf7ToUtf8('foo &- bar'));
    }

    public function testBuildAssignsTag(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $command = $builder->command('SELECT')->quoted('INBOX')->build();

        self::assertSame('A0001', $command->tag->value);
        self::assertSame('SELECT', $command->name);
        self::assertSame(['"INBOX"'], $command->arguments);
        self::assertSame("A0001 SELECT \"INBOX\"\r\n", $command->compile());
    }
}
