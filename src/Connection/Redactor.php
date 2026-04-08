<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection;

/**
 * Stateful single-line redactor for IMAP credentials.
 *
 * Handles the credential-bearing variants:
 *
 *  - LOGIN: tagged single-line command, two trailing arguments are user/pass.
 *  - AUTHENTICATE <mechanism> <initial-response>: SASL-IR (RFC 4959) form, the
 *    trailing argument is a base64-encoded SASL payload.
 *  - AUTHENTICATE <mechanism> followed on the next write by a bare base64 line:
 *    classic continuation form. The base64 payload is sent only after the server
 *    replies with "+ ...". We track that state across calls so the very next
 *    write that looks like a base64 blob is replaced.
 *
 * The redactor is intentionally conservative — anything that does not clearly
 * match a credential pattern is returned unchanged. We prefer occasional
 * under-redaction (visible at review time) to silent over-redaction that would
 * mask unrelated bugs.
 *
 * NOTE: even with redaction enabled, logs may still contain sensitive data
 * (subjects, recipients, message bodies). Always review before sharing.
 */
final class Redactor
{
    private bool $expectingAuthPayload = false;

    /**
     * Redact a single wire line.
     *
     * Pass the line as it appears on the wire after stripping the trailing
     * CRLF — i.e. what LoggingConnection passes to log().
     */
    public function redact(string $line): string
    {
        // LOGIN command: "<tag> LOGIN <user> <pass>"
        if (preg_match('/^(\S+\s+LOGIN)\s+\S+\s+\S+\s*$/i', $line, $m) === 1) {
            $this->expectingAuthPayload = false;

            return $m[1] . ' *** ***';
        }

        // AUTHENTICATE with SASL-IR: "<tag> AUTHENTICATE <mech> <base64>"
        if (preg_match('/^(\S+\s+AUTHENTICATE\s+\S+)\s+\S+\s*$/i', $line, $m) === 1) {
            $this->expectingAuthPayload = false;

            return $m[1] . ' ***';
        }

        // AUTHENTICATE without SASL-IR: "<tag> AUTHENTICATE <mech>"
        // Arm the continuation flag — the next write will be the base64 payload
        // (assuming the server replied "+").
        if (preg_match('/^\S+\s+AUTHENTICATE\s+\S+\s*$/i', $line) === 1) {
            $this->expectingAuthPayload = true;

            return $line;
        }

        // Continuation payload: a bare base64 blob right after AUTHENTICATE.
        if ($this->expectingAuthPayload) {
            $this->expectingAuthPayload = false;

            if ($line !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $line) === 1) {
                return '*** [redacted auth payload]';
            }
        }

        return $line;
    }
}
