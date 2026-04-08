<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Command;

use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\TagGenerator;
use D4ry\ImapClient\Support\Literal;
use D4ry\ImapClient\ValueObject\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandBuilder::class)]
#[CoversClass(Command::class)]
#[UsesClass(TagGenerator::class)]
#[UsesClass(Tag::class)]
#[UsesClass(Literal::class)]
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

    public function testAtomAppendsRawArgument(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $command = $builder->command('STORE')->atom('1:5')->atom('+FLAGS')->build();

        self::assertSame(['1:5', '+FLAGS'], $command->arguments);
        self::assertSame("A0001 STORE 1:5 +FLAGS\r\n", $command->compile());
    }

    public function testQuotedEscapesBackslashAndQuote(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $command = $builder->command('LOGIN')->quoted('a\\b"c')->build();

        self::assertSame(['"a\\\\b\\"c"'], $command->arguments);
    }

    public function testListJoinsItemsInParens(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $command = $builder->command('STORE')->list(['\\Seen', '\\Deleted'])->build();

        self::assertSame(['(\\Seen \\Deleted)'], $command->arguments);
    }

    public function testLiteralAppendsSizeMarkerSync(): void
    {
        $literal = new Literal('hello');
        $builder = (new CommandBuilder(new TagGenerator()))
            ->command('APPEND')
            ->literal($literal);
        $command = $builder->build();

        self::assertSame(['{5}'], $command->arguments);
        self::assertSame([$literal], $builder->getLiterals());
    }

    public function testLiteralAppendsSizeMarkerNonSync(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $literal = new Literal('hello', nonSynchronizing: true);
        $builder = $builder->command('APPEND')->literal($literal);
        $command = $builder->build();

        self::assertSame(['{5+}'], $command->arguments);
        self::assertCount(1, $builder->getLiterals());
        self::assertTrue($builder->getLiterals()[0]->nonSynchronizing);
    }

    public function testRawAppendsValueUnchanged(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $command = $builder->command('UID')->raw('SEARCH 1:*')->build();

        self::assertSame(['SEARCH 1:*'], $command->arguments);
        self::assertSame("A0001 UID SEARCH 1:*\r\n", $command->compile());
    }

    public function testBuildWithTagUsesProvidedTagAndDoesNotAdvanceGenerator(): void
    {
        $generator = new TagGenerator();
        $builder = new CommandBuilder($generator);
        $command = $builder->command('NOOP')->buildWithTag(new Tag('Z9999'));

        self::assertSame('Z9999', $command->tag->value);
        self::assertSame('NOOP', $command->name);
        // Generator was not consumed
        self::assertSame('A0001', $generator->next()->value);
    }

    public function testCommandClonesBuilderStateAcrossCalls(): void
    {
        $generator = new TagGenerator();
        $builder = new CommandBuilder($generator);

        $first = $builder->command('NOOP')->atom('FOO');
        $second = $builder->command('SELECT')->quoted('INBOX');

        self::assertSame(['FOO'], $first->build()->arguments);
        self::assertSame(['"INBOX"'], $second->build()->arguments);
    }

    public function testCommandResetsLiteralsOnNewCommand(): void
    {
        $builder = new CommandBuilder(new TagGenerator());
        $first = $builder->command('APPEND')->literal(new Literal('a'));
        self::assertCount(1, $first->getLiterals());

        $second = $builder->command('NOOP');
        self::assertSame([], $second->getLiterals());
    }

    public function testQuoteStringWithControlCharacterIsQuoted(): void
    {
        // Control character → triggers the [\x00-\x1f] branch.
        self::assertSame('"a\\\\b"', CommandBuilder::quoteString('a\\b'));
        self::assertSame("\"a\x01b\"", CommandBuilder::quoteString("a\x01b"));
    }

    public function testQuoteStringWithNonAtomFriendlyCharIsQuoted(): void
    {
        // '*' is neither in the special-trigger set nor in the atom-safe set,
        // so it falls through to the final quoting branch.
        self::assertSame('"foo*bar"', CommandBuilder::quoteString('foo*bar'));
    }

    public function testEncodeMailboxNameUtf7ModeQuotesWhenContainsSpace(): void
    {
        // Non-UTF8 mode + space → quoteString wraps it.
        self::assertSame('"My Folder"', CommandBuilder::encodeMailboxName('My Folder'));
    }

    public function testDecodeMailboxNameUtf8ModePassThrough(): void
    {
        self::assertSame('Entwürfe', CommandBuilder::decodeMailboxName('Entwürfe', utf8Enabled: true));
    }

    public function testModifiedUtf7DecodeFastPathPureAscii(): void
    {
        // String without '&' takes the fast-path branch.
        self::assertSame('INBOX', CommandBuilder::modifiedUtf7ToUtf8('INBOX'));
    }

    public function testUtf8ToModifiedUtf7AsciiFastPath(): void
    {
        self::assertSame('INBOX', CommandBuilder::utf8ToModifiedUtf7('INBOX'));
    }

    public function testUtf8ToModifiedUtf7HandlesAmpersandInsideNonAsciiRun(): void
    {
        // Mix non-ASCII then '&' then non-ASCII to exercise the buffer-flush
        // branch when encountering '&' while inside a non-ASCII run.
        $input = 'ü&ü';
        $encoded = CommandBuilder::utf8ToModifiedUtf7($input);

        self::assertSame($input, CommandBuilder::modifiedUtf7ToUtf8($encoded));
    }
}
