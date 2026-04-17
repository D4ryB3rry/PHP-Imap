<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\MailboxStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailboxStatus::class)]
final class MailboxStatusTest extends TestCase
{
    public function testDefaults(): void
    {
        $status = new MailboxStatus();

        self::assertSame(0, $status->messages);
        self::assertSame(0, $status->recent);
        self::assertSame(0, $status->uidNext);
        self::assertSame(0, $status->uidValidity);
        self::assertSame(0, $status->unseen);
        self::assertNull($status->highestModSeq);
        self::assertNull($status->size);
        self::assertNull($status->mailboxId);
    }

    public function testCustomValues(): void
    {
        $status = new MailboxStatus(
            messages: 42,
            recent: 3,
            uidNext: 100,
            uidValidity: 1234567890,
            unseen: 7,
            highestModSeq: 999,
            size: 8192,
            mailboxId: 'F2212ea5-some-unique-id',
        );

        self::assertSame(42, $status->messages);
        self::assertSame(3, $status->recent);
        self::assertSame(100, $status->uidNext);
        self::assertSame(1234567890, $status->uidValidity);
        self::assertSame(7, $status->unseen);
        self::assertSame(999, $status->highestModSeq);
        self::assertSame(8192, $status->size);
        self::assertSame('F2212ea5-some-unique-id', $status->mailboxId);
    }

    public function testFromStatusAttributesMapsAllFields(): void
    {
        $status = MailboxStatus::fromStatusAttributes([
            'MESSAGES' => 10,
            'RECENT' => 2,
            'UIDNEXT' => 50,
            'UIDVALIDITY' => 42,
            'UNSEEN' => 3,
            'HIGHESTMODSEQ' => 77,
            'SIZE' => 4096,
            'MAILBOXID' => 'F2212ea5-abc',
        ]);

        self::assertSame(10, $status->messages);
        self::assertSame(2, $status->recent);
        self::assertSame(50, $status->uidNext);
        self::assertSame(42, $status->uidValidity);
        self::assertSame(3, $status->unseen);
        self::assertSame(77, $status->highestModSeq);
        self::assertSame(4096, $status->size);
        self::assertSame('F2212ea5-abc', $status->mailboxId);
    }

    public function testFromStatusAttributesDefaultsForMissingKeys(): void
    {
        $status = MailboxStatus::fromStatusAttributes([]);

        self::assertSame(0, $status->messages);
        self::assertSame(0, $status->recent);
        self::assertSame(0, $status->uidNext);
        self::assertSame(0, $status->uidValidity);
        self::assertSame(0, $status->unseen);
        self::assertNull($status->highestModSeq);
        self::assertNull($status->size);
        self::assertNull($status->mailboxId);
    }

    public function testFromStatusAttributesPartialAttributes(): void
    {
        $status = MailboxStatus::fromStatusAttributes([
            'MESSAGES' => 5,
            'UIDVALIDITY' => 99,
        ]);

        self::assertSame(5, $status->messages);
        self::assertSame(0, $status->recent);
        self::assertSame(0, $status->uidNext);
        self::assertSame(99, $status->uidValidity);
        self::assertSame(0, $status->unseen);
        self::assertNull($status->highestModSeq);
        self::assertNull($status->size);
        self::assertNull($status->mailboxId);
    }
}
