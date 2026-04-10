<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\Address;
use D4ry\ImapClient\ValueObject\Envelope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\Envelope
 * @uses \D4ry\ImapClient\ValueObject\Address
 */
final class EnvelopeTest extends TestCase
{
    public function testHoldsAllFields(): void
    {
        $date = new \DateTimeImmutable('2026-04-07 10:00:00');
        $from = [new Address('Alice', 'alice', 'example.com')];
        $to = [new Address('Bob', 'bob', 'example.com')];

        $env = new Envelope(
            date: $date,
            subject: 'Hello',
            from: $from,
            sender: $from,
            replyTo: $from,
            to: $to,
            cc: [],
            bcc: [],
            inReplyTo: null,
            messageId: '<id@example.com>',
        );

        self::assertSame('Hello', $env->subject);
        self::assertSame($date, $env->date);
        self::assertSame($from, $env->from);
        self::assertSame($to, $env->to);
        self::assertSame([], $env->cc);
        self::assertSame('<id@example.com>', $env->messageId);
        self::assertNull($env->inReplyTo);
    }
}
