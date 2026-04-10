<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\MailboxStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\MailboxStatus
 */
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
        );

        self::assertSame(42, $status->messages);
        self::assertSame(3, $status->recent);
        self::assertSame(100, $status->uidNext);
        self::assertSame(1234567890, $status->uidValidity);
        self::assertSame(7, $status->unseen);
        self::assertSame(999, $status->highestModSeq);
        self::assertSame(8192, $status->size);
    }
}
