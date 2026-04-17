<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Notify;

use D4ry\ImapClient\Notify\MailboxFilter;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailboxFilter::class)]
#[UsesClass(CommandBuilder::class)]
final class MailboxFilterTest extends TestCase
{
    public function testBareFiltersEmitSingleKeyword(): void
    {
        self::assertSame('selected', MailboxFilter::selected()->toFilterToken(false));
        self::assertSame('selected-delayed', MailboxFilter::selectedDelayed()->toFilterToken(false));
        self::assertSame('inboxes', MailboxFilter::inboxes()->toFilterToken(false));
        self::assertSame('personal', MailboxFilter::personal()->toFilterToken(false));
        self::assertSame('subscribed', MailboxFilter::subscribed()->toFilterToken(false));
    }

    public function testSubtreeSingleMailboxEmitsBareName(): void
    {
        $filter = MailboxFilter::subtree(['Archive']);

        self::assertSame('subtree Archive', $filter->toFilterToken(true));
    }

    public function testSubtreeMultipleMailboxesEmitsParenthesisedList(): void
    {
        $filter = MailboxFilter::subtree(['Archive', 'Archive/2026']);

        self::assertSame('subtree (Archive Archive/2026)', $filter->toFilterToken(true));
    }

    public function testMailboxesEncodesNamesViaModifiedUtf7WhenUtf8Disabled(): void
    {
        $filter = MailboxFilter::mailboxes(['Fahrräder']);

        // & is the escape sentinel for modified-UTF-7, non-ASCII name must not appear verbatim.
        $wire = $filter->toFilterToken(false);

        self::assertStringStartsWith('mailboxes ', $wire);
        self::assertStringNotContainsString('Fahrräder', $wire);
    }

    public function testMailboxesPassesThroughWhenUtf8Enabled(): void
    {
        $filter = MailboxFilter::mailboxes(['Fahrräder']);

        self::assertSame('mailboxes "Fahrräder"', $filter->toFilterToken(true));
    }

    public function testSubtreeRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MailboxFilter::subtree([]);
    }

    public function testMailboxesRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MailboxFilter::mailboxes([]);
    }

    public function testSubtreeReindexesAssociativeKeys(): void
    {
        // Kills UnwrapArrayValues at MailboxFilter.php:66 — the factory must
        // normalise to sequential 0..n keys regardless of caller-provided
        // associative keys.
        $filter = MailboxFilter::subtree(['a' => 'Foo', 'b' => 'Bar']);

        self::assertSame([0, 1], array_keys($filter->mailboxes));
        self::assertSame(['Foo', 'Bar'], $filter->mailboxes);
    }

    public function testMailboxesReindexesAssociativeKeys(): void
    {
        // Kills UnwrapArrayValues at MailboxFilter.php:78.
        $filter = MailboxFilter::mailboxes(['x' => 'One', 'y' => 'Two']);

        self::assertSame([0, 1], array_keys($filter->mailboxes));
        self::assertSame(['One', 'Two'], $filter->mailboxes);
    }
}
