<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase
{
    public function testLoginWithQuotedArgsIsRedacted(): void
    {
        $r = new Redactor();
        self::assertSame('A0001 LOGIN *** ***', $r->redact('A0001 LOGIN "user@example.com" "hunter2"'));
    }

    public function testLoginWithBareArgsIsRedacted(): void
    {
        $r = new Redactor();
        self::assertSame('A0001 LOGIN *** ***', $r->redact('A0001 LOGIN alice s3cret'));
    }

    public function testAuthenticatePlainSaslIrIsRedacted(): void
    {
        $r = new Redactor();
        self::assertSame('A0001 AUTHENTICATE PLAIN ***', $r->redact('A0001 AUTHENTICATE PLAIN AGZvbwBiYXI='));
    }

    public function testAuthenticateXoauth2SaslIrIsRedacted(): void
    {
        $r = new Redactor();
        self::assertSame(
            'A0001 AUTHENTICATE XOAUTH2 ***',
            $r->redact('A0001 AUTHENTICATE XOAUTH2 dXNlcj1mb29AZXhhbXBsZS5jb20BYXV0aD1CZWFyZXIgVE9LRU4BAQ=='),
        );
    }

    public function testAuthenticateContinuationFlowRedactsNextLine(): void
    {
        $r = new Redactor();

        self::assertSame('A0001 AUTHENTICATE PLAIN', $r->redact('A0001 AUTHENTICATE PLAIN'));
        self::assertSame('*** [redacted auth payload]', $r->redact('AGZvbwBiYXI='));

        // Subsequent unrelated commands stay untouched.
        self::assertSame('A0002 NOOP', $r->redact('A0002 NOOP'));
    }

    public function testContinuationFlagClearsOnNonBase64Followup(): void
    {
        $r = new Redactor();

        $r->redact('A0001 AUTHENTICATE PLAIN');

        // A non-base64 line (e.g. the client decided to abort) clears the flag
        // and is returned unchanged.
        self::assertSame('*', $r->redact('*'));

        // The next bare base64 line must NOT be redacted because the flag cleared.
        self::assertSame('AGZvbwBiYXI=', $r->redact('AGZvbwBiYXI='));
    }

    public function testUnrelatedCommandsArePassthrough(): void
    {
        $r = new Redactor();
        self::assertSame('A0001 SELECT INBOX', $r->redact('A0001 SELECT INBOX'));
        self::assertSame('A0002 LIST "" "*"', $r->redact('A0002 LIST "" "*"'));
        self::assertSame('* OK Dovecot ready.', $r->redact('* OK Dovecot ready.'));
    }
}
