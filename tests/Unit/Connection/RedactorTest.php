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

    // ----- Anchor / flag kill tests for the LOGIN regex (line 41) -----

    public function testLoginRegexRequiresStartOfStringAnchor(): void
    {
        // Without `^`, the regex would still match "A0001 LOGIN x y" inside
        // a longer line. The line has a prefix that shifts LOGIN off the start,
        // so the *anchored* regex must NOT match and the line must pass through
        // unchanged. Kills PregMatchRemoveCaret on line 41.
        $r = new Redactor();
        $line = 'GARBAGE A0001 LOGIN user pass';
        self::assertSame($line, $r->redact($line));
    }

    public function testLoginRegexRequiresEndOfStringAnchor(): void
    {
        // Without `$`, the regex would match the LOGIN prefix and ignore
        // trailing garbage. With the anchor, the trailing third token must
        // cause the pattern to fail and the line to pass through unchanged.
        // Kills PregMatchRemoveDollar on line 41.
        $r = new Redactor();
        $line = 'A0001 LOGIN user pass extra';
        self::assertSame($line, $r->redact($line));
    }

    public function testLoginRegexIsCaseInsensitive(): void
    {
        // The /i flag is what allows lowercase "login" to match. Removing it
        // would make this line pass through. Kills PregMatchRemoveFlags on
        // line 41.
        $r = new Redactor();
        self::assertSame('a0001 login *** ***', $r->redact('a0001 login alice s3cret'));
    }

    public function testLoginDoesNotArmTheContinuationFlag(): void
    {
        // After a successful LOGIN redaction, $expectingAuthPayload must remain
        // false — otherwise an unrelated bare base64-looking line would get
        // redacted as a "continuation payload". Kills FalseValue on line 42.
        $r = new Redactor();
        $r->redact('A0001 LOGIN alice s3cret');
        self::assertSame('AGZvbwBiYXI=', $r->redact('AGZvbwBiYXI='));
    }

    // ----- Anchor / flag kill tests for AUTHENTICATE-SASL-IR (line 48) -----

    public function testAuthenticateSaslIrRegexRequiresStartOfStringAnchor(): void
    {
        // Kills PregMatchRemoveCaret on line 48.
        $r = new Redactor();
        $line = 'GARBAGE A0001 AUTHENTICATE PLAIN AGZvbwBiYXI=';
        self::assertSame($line, $r->redact($line));
    }

    public function testAuthenticateSaslIrRegexRequiresEndOfStringAnchor(): void
    {
        // Kills PregMatchRemoveDollar on line 48.
        $r = new Redactor();
        $line = 'A0001 AUTHENTICATE PLAIN AGZvbwBiYXI= extra';
        self::assertSame($line, $r->redact($line));
    }

    public function testAuthenticateSaslIrRegexIsCaseInsensitive(): void
    {
        // Kills PregMatchRemoveFlags on line 48.
        $r = new Redactor();
        self::assertSame(
            'a0001 authenticate plain ***',
            $r->redact('a0001 authenticate plain AGZvbwBiYXI='),
        );
    }

    public function testAuthenticateSaslIrDoesNotArmTheContinuationFlag(): void
    {
        // After a SASL-IR redaction, the next bare base64 line must NOT be
        // treated as a continuation payload — assigning true to the flag
        // (FalseValue mutant on line 49) would cause the next line to be
        // redacted instead of passed through.
        $r = new Redactor();
        $r->redact('A0001 AUTHENTICATE PLAIN AGZvbwBiYXI=');
        self::assertSame('AGZvbwBiYXI=', $r->redact('AGZvbwBiYXI='));
    }

    // ----- Anchor / flag kill tests for AUTHENTICATE-no-IR (line 57) -----

    public function testAuthenticateNoIrRegexRequiresStartOfStringAnchor(): void
    {
        // Kills PregMatchRemoveCaret on line 57. The line passes through
        // unchanged AND the continuation flag must NOT arm — assert both by
        // sending a base64-looking follow-up that should remain visible.
        $r = new Redactor();
        $line = 'GARBAGE A0001 AUTHENTICATE PLAIN';
        self::assertSame($line, $r->redact($line));
        self::assertSame('AGZvbwBiYXI=', $r->redact('AGZvbwBiYXI='));
    }

    public function testAuthenticateNoIrRegexRequiresEndOfStringAnchor(): void
    {
        // Kills PregMatchRemoveDollar on line 57. We need a line that does
        // NOT match the SASL-IR pattern on line 48 either (otherwise line 57
        // is unreachable), so use 5 tokens — line 48 requires exactly 4 with
        // its end-of-string anchor. Without `$` on line 57, the no-IR regex
        // would still arm the continuation flag and the next bare base64 line
        // would be redacted.
        $r = new Redactor();
        $line = 'A0001 AUTHENTICATE PLAIN extra junk';
        self::assertSame($line, $r->redact($line));
        self::assertSame('AGZvbwBiYXI=', $r->redact('AGZvbwBiYXI='));
    }

    public function testAuthenticateNoIrRegexIsCaseInsensitive(): void
    {
        // Kills PregMatchRemoveFlags on line 57. With /i removed, the lowercase
        // "authenticate" line would not match and the continuation flag would
        // not arm, so the next base64 line would NOT be redacted.
        $r = new Redactor();
        self::assertSame('a0001 authenticate plain', $r->redact('a0001 authenticate plain'));
        self::assertSame('*** [redacted auth payload]', $r->redact('AGZvbwBiYXI='));
    }

    // ----- Anchor kill tests for the base64 continuation regex (line 67) -----

    public function testContinuationPayloadRegexRequiresStartOfStringAnchor(): void
    {
        // The continuation-payload regex must reject lines whose base64-looking
        // tail is preceded by non-base64 characters. Without `^`, " AGZvbw=="
        // (leading space) would still match the tail and get redacted. Kills
        // PregMatchRemoveCaret on line 67.
        $r = new Redactor();
        $r->redact('A0001 AUTHENTICATE PLAIN');
        // Leading space — not pure base64 — must pass through.
        self::assertSame(' AGZvbwBiYXI=', $r->redact(' AGZvbwBiYXI='));
    }

    public function testContinuationPayloadRegexRequiresEndOfStringAnchor(): void
    {
        // Without `$`, "AGZvbw== junk" would match the base64 prefix and get
        // redacted. Kills PregMatchRemoveDollar on line 67.
        $r = new Redactor();
        $r->redact('A0001 AUTHENTICATE PLAIN');
        self::assertSame('AGZvbwBiYXI= junk', $r->redact('AGZvbwBiYXI= junk'));
    }
}
