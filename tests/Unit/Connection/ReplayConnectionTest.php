<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\Redactor;
use D4ry\ImapClient\Connection\ReplayConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\ReplayMismatchException;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Connection\ReplayConnection
 * @covers \D4ry\ImapClient\Connection\RecordingConnection
 * @covers \D4ry\ImapClient\Connection\Redactor
 */
final class ReplayConnectionTest extends TestCase
{
    private string $recordPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imap-replay-');
        self::assertNotFalse($path);
        $this->recordPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->recordPath)) {
            @unlink($this->recordPath);
        }
    }

    public function testRoundTripFromRecording(): void
    {
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);
        self::assertSame("* OK ready\r\n", $replay->readLine());
        self::assertSame('hello world', $replay->readBytes(11));
        $replay->write("a01 NOOP\r\n");
        $replay->close();

        self::assertFalse($replay->isConnected());
        self::assertSame([], $replay->mismatches);
    }

    public function testStrictWriteMismatchThrows(): void
    {
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);
        $replay->readLine();
        $replay->readBytes(11);

        $this->expectException(ReplayMismatchException::class);
        $replay->write("a01 LOGOUT\r\n");
    }

    public function testNonStrictWriteMismatchCollects(): void
    {
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath, strict: false);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);
        $replay->readLine();
        $replay->readBytes(11);
        $replay->write("a01 LOGOUT\r\n");

        self::assertCount(1, $replay->mismatches);
        self::assertStringContainsString('write mismatch', $replay->mismatches[0]);
    }

    public function testRedactedLoginLineIsAcceptedFromLiveCredential(): void
    {
        $this->writeJsonl([
            ['t' => 'open', 'host' => 'h', 'port' => 993, 'encryption' => 'Tls', 'timeout' => 5.0],
            ['t' => 'open_ok'],
            ['t' => 'write', 'data' => "a01 LOGIN *** ***\r\n"],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('h', 993, Encryption::Tls, 5.0);
        $replay->write("a01 LOGIN user@example.com s3cret!\r\n");

        self::assertSame([], $replay->mismatches);
    }

    public function testRedactedAuthContinuationPayloadIsAcceptedFromLiveCredential(): void
    {
        $this->writeJsonl([
            ['t' => 'open', 'host' => 'h', 'port' => 993, 'encryption' => 'Tls', 'timeout' => 5.0],
            ['t' => 'open_ok'],
            ['t' => 'write', 'data' => "a01 AUTHENTICATE PLAIN\r\n"],
            ['t' => 'write', 'data' => "*** [redacted auth payload]\r\n"],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('h', 993, Encryption::Tls, 5.0);
        $replay->write("a01 AUTHENTICATE PLAIN\r\n");
        $replay->write("AHVzZXIAcGFzcw==\r\n");

        self::assertSame([], $replay->mismatches);
    }

    public function testReadBytesCountMismatchThrows(): void
    {
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);
        $replay->readLine();

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('readBytes count mismatch');
        $replay->readBytes(7);
    }

    public function testWrongEventTypeThrows(): void
    {
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "write"');
        // Next event is read_line, not write.
        $replay->write("a01 NOOP\r\n");
    }

    public function testInvalidJsonlThrows(): void
    {
        file_put_contents($this->recordPath, "not json\n");

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid JSONL on line 1');
        new ReplayConnection($this->recordPath);
    }

    public function testEventWithoutTypeFieldThrows(): void
    {
        file_put_contents($this->recordPath, "{\"foo\":1}\n");

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Invalid event on line 1');
        new ReplayConnection($this->recordPath);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to read replay file');
        new ReplayConnection('/nonexistent-dir-xyz-9f8e/replay.jsonl');
    }

    public function testOpenErrEventIsRethrown(): void
    {
        $this->writeJsonl([
            ['t' => 'open', 'host' => 'h', 'port' => 1, 'encryption' => 'Tls', 'timeout' => 1.0],
            ['t' => 'open_err', 'message' => 'connect refused'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connect refused');
        $replay->open('h', 1, Encryption::Tls, 1.0);
    }

    public function testReadLineErrorEventIsRethrown(): void
    {
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'read_line', 'message' => 'eof'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('eof');
        $replay->readLine();
    }

    public function testReadBytesErrorEventIsRethrown(): void
    {
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'read_bytes', 'message' => 'short read'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('short read');
        $replay->readBytes(10);
    }

    public function testWriteErrorEventIsRethrown(): void
    {
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'write', 'message' => 'broken pipe'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('broken pipe');
        $replay->write("a01 NOOP\r\n");
    }

    public function testReadBytesWithInvalidBase64Throws(): void
    {
        $this->writeJsonl([
            ['t' => 'read_bytes', 'count' => 4, 'data' => '!!!not-base64!!!'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('not valid base64');
        $replay->readBytes(4);
    }

    public function testEnableTlsConsumesTlsAndTlsOk(): void
    {
        $this->writeJsonl([
            ['t' => 'tls'],
            ['t' => 'tls_ok'],
            ['t' => 'close'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->enableTls();
        $replay->close();

        self::assertFalse($replay->isConnected());
    }

    public function testEnableTlsErrEventIsRethrown(): void
    {
        $this->writeJsonl([
            ['t' => 'tls'],
            ['t' => 'tls_err', 'message' => 'handshake failed'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('handshake failed');
        $replay->enableTls();
    }

    public function testReplayExhaustedThrows(): void
    {
        $this->writeJsonl([]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('Replay exhausted');
        $replay->readLine();
    }

    public function testSetReadTimeoutIsNoop(): void
    {
        $this->writeJsonl([
            ['t' => 'close'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->setReadTimeout(7.5); // No-op; must not advance cursor or throw.
        $replay->close();

        self::assertFalse($replay->isConnected());
    }

    public function testStreamBytesToWritesIntoSinkFromRecording(): void
    {
        $payload = 'streamed-bytes';
        $this->writeJsonl([
            ['t' => 'read_bytes', 'count' => strlen($payload), 'data' => base64_encode($payload)],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $sink = fopen('php://memory', 'w+b');
        self::assertNotFalse($sink);

        try {
            $replay->streamBytesTo($sink, strlen($payload));

            rewind($sink);
            self::assertSame($payload, stream_get_contents($sink));
        } finally {
            fclose($sink);
        }
    }

    public function testStreamBytesToThrowsWhenSinkRejectsWrite(): void
    {
        $payload = 'payload';
        $this->writeJsonl([
            ['t' => 'read_bytes', 'count' => strlen($payload), 'data' => base64_encode($payload)],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $sinkPath = tempnam(sys_get_temp_dir(), 'imap-replay-sink-');
        self::assertNotFalse($sinkPath);
        $sink = fopen($sinkPath, 'rb');
        self::assertNotFalse($sink);

        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Failed to write to literal sink');
            $replay->streamBytesTo($sink, strlen($payload));
        } finally {
            restore_error_handler();
            fclose($sink);
            @unlink($sinkPath);
        }
    }

    public function testStringFieldRejectsNonString(): void
    {
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 42],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('field "data" must be a string');
        $replay->readLine();
    }

    public function testRoundTripFromRecordingReportsConnectedBetweenOpenAndClose(): void
    {
        // Pins ReplayConnection::open() to set $connected = true (kills the
        // TrueValue mutant on line 91) and asserts isConnected returns true
        // *between* open() and close().
        $this->captureSession(redact: false);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('imap.example.com', 993, Encryption::Tls, 5.0);

        self::assertTrue($replay->isConnected(), 'open() must set the connected flag to true');

        $replay->readLine();
        $replay->readBytes(11);
        $replay->write("a01 NOOP\r\n");
        $replay->close();

        self::assertFalse($replay->isConnected());
    }

    public function testJsonlBlankLinesAreSkippedNotTreatedAsTerminator(): void
    {
        // The blank-line guard in the constructor uses `continue` (skip and
        // keep parsing) — mutating to `break` would silently drop everything
        // after the first blank line. We embed a blank line *between* two
        // valid events and assert the second event is still reachable.
        $payload = 'after-blank';
        file_put_contents(
            $this->recordPath,
            json_encode(['t' => 'read_line', 'data' => "first\r\n"]) . "\n"
                . "\n"
                . json_encode(['t' => 'read_line', 'data' => $payload]) . "\n",
        );

        $replay = new ReplayConnection($this->recordPath);
        self::assertSame("first\r\n", $replay->readLine());
        self::assertSame($payload, $replay->readLine());
    }

    public function testOpenAdvancesPastOpenOk(): void
    {
        // Asserts the open_ok branch advances the cursor (kills Increment on
        // line 88) AND that the LogicalAnd guard short-circuits correctly
        // when the follow event isn't open_ok (here open is the only event).
        // The follow-up readLine after a clean open must consume the next
        // event, proving the cursor is positioned correctly.
        $this->writeJsonl([
            ['t' => 'open'],
            ['t' => 'open_ok'],
            ['t' => 'read_line', 'data' => 'after-open'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('h', 1, Encryption::Tls, 1.0);

        self::assertSame('after-open', $replay->readLine());
    }

    public function testOpenWithNoFollowEventDoesNotAccessNullFollow(): void
    {
        // Kills the LogicalAnd → LogicalOr mutant on line 87: with `||`,
        // a null $follow would short-circuit on the first operand and then
        // attempt $follow['t'] on null, raising a TypeError. Original `&&`
        // short-circuits at $follow !== null and leaves $follow alone.
        $this->writeJsonl([
            ['t' => 'open'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->open('h', 1, Encryption::Tls, 1.0);

        self::assertTrue($replay->isConnected());
    }

    public function testReadLineErrorAdvancesCursorPastErrorEvent(): void
    {
        // After a recorded read_line error is rethrown, the cursor must have
        // moved past the error event so a subsequent call sees the *next*
        // event. Decrementing instead of incrementing (or short-circuiting
        // the cursor++) leaves the cursor on the error and the next call
        // sees it again. Kills Increment on line 104.
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'read_line', 'message' => 'eof'],
            ['t' => 'read_line', 'data' => 'after-error'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->readLine();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // expected
        }

        self::assertSame('after-error', $replay->readLine());
    }

    public function testReadLineIgnoresErrorEventWithDifferentOp(): void
    {
        // The readLine error guard must only trigger for op === 'read_line'.
        // An error event with op === 'write' must fall through to expect()
        // which raises a ReplayMismatchException, NOT a ConnectionException.
        // Kills the Identical/LogicalAnd mutants on line 103.
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'write', 'message' => 'broken pipe'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "read_line" but found "error"');
        $replay->readLine();
    }

    public function testReadBytesErrorAdvancesCursorPastErrorEvent(): void
    {
        // Kills Increment on line 118 (cursor++ in read_bytes error branch).
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'read_bytes', 'message' => 'short read'],
            ['t' => 'read_line', 'data' => 'after-error'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->readBytes(10);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // expected
        }

        self::assertSame('after-error', $replay->readLine());
    }

    public function testReadBytesIgnoresErrorEventWithDifferentOp(): void
    {
        // Kills Identical/LogicalAnd mutants on line 117.
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'write', 'message' => 'broken pipe'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "read_bytes" but found "error"');
        $replay->readBytes(10);
    }

    public function testReadBytesCountMismatchMessageReportsExactCursor(): void
    {
        // The cursor offset embedded in the count-mismatch message is
        // `$this->cursor - 1` (the index of the *just-consumed* read_bytes
        // event). Pin it to a known absolute index so DecrementInteger,
        // IncrementInteger and Minus mutants on line 128 are caught.
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 'first'],
            ['t' => 'read_bytes', 'count' => 5, 'data' => base64_encode('hello')],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->readLine();

        try {
            $replay->readBytes(7);
            self::fail('Expected ReplayMismatchException');
        } catch (ReplayMismatchException $e) {
            // The just-consumed read_bytes is at events[1] → cursor - 1 == 1.
            self::assertStringContainsString('Replay event 1:', $e->getMessage());
        }
    }

    public function testReadBytesInvalidBase64MessageReportsExactCursor(): void
    {
        // Kills Decrement/Increment/Minus on line 137.
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 'first'],
            ['t' => 'read_bytes', 'count' => 4, 'data' => '!!!not-base64!!!'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->readLine();

        try {
            $replay->readBytes(4);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('Replay event 1:', $e->getMessage());
        }
    }

    public function testReadBytesAcceptsRecordedCountFallbackForNonIntField(): void
    {
        // The fallback in `isset && is_int ? : -1` returns -1 when count is
        // missing or non-int. We craft an event whose `count` is a string —
        // is_int(false) → fallback to -1. Then we request 0 bytes; -1 !== 0
        // triggers the mismatch. Killing IncrementInteger/DecrementInteger
        // on line 123 requires the fallback to be exactly -1 because the
        // mismatch message reports `recorded=-1`.
        // The LogicalAnd → LogicalOr mutant on line 123 (`isset && is_int`
        // → `isset || is_int`) is killed by the same path: with `||`, a
        // missing-key event would still pass the gate via is_int(null)=false,
        // but here the field is present-and-non-int so original gives -1
        // and mutated would access $event['count'] returning the string,
        // producing a different recorded= value in the message.
        $this->writeJsonl([
            ['t' => 'read_bytes', 'count' => 'nope', 'data' => base64_encode('x')],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->readBytes(0);
            self::fail('Expected ReplayMismatchException');
        } catch (ReplayMismatchException $e) {
            self::assertStringContainsString('recorded=-1', $e->getMessage());
        }
    }

    public function testWriteErrorAdvancesCursorPastErrorEvent(): void
    {
        // Kills Increment on line 160.
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'write', 'message' => 'broken pipe'],
            ['t' => 'read_line', 'data' => 'after-error'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->write("a01 NOOP\r\n");
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // expected
        }

        self::assertSame('after-error', $replay->readLine());
    }

    public function testWriteIgnoresErrorEventWithDifferentOp(): void
    {
        // Kills Identical/LogicalAnd mutants on line 159.
        $this->writeJsonl([
            ['t' => 'error', 'op' => 'read_line', 'message' => 'eof'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "write" but found "error"');
        $replay->write("a01 NOOP\r\n");
    }

    public function testWriteMismatchMessageReportsExactCursor(): void
    {
        // Kills Decrement/Increment/Minus on line 170.
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 'first'],
            ['t' => 'write', 'data' => "expected\r\n"],
        ]);

        $replay = new ReplayConnection($this->recordPath, strict: false);
        $replay->readLine();
        $replay->write("actually-different\r\n");

        self::assertCount(1, $replay->mismatches);
        self::assertStringContainsString('Replay event 1:', $replay->mismatches[0]);
    }

    public function testEnableTlsAdvancesPastTlsErr(): void
    {
        // Kills Increment on line 189 — after rethrowing tls_err the cursor
        // must be past it so a subsequent call sees the next event.
        $this->writeJsonl([
            ['t' => 'tls'],
            ['t' => 'tls_err', 'message' => 'handshake failed'],
            ['t' => 'read_line', 'data' => 'after-tls-err'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->enableTls();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // expected
        }

        self::assertSame('after-tls-err', $replay->readLine());
    }

    public function testEnableTlsAdvancesPastTlsOk(): void
    {
        // Kills Increment on line 194 + the LogicalAnd / Identical /
        // NotIdentical / negation cluster on line 193: the follow-up read
        // after enableTls() must land on the event right after tls_ok.
        $this->writeJsonl([
            ['t' => 'tls'],
            ['t' => 'tls_ok'],
            ['t' => 'read_line', 'data' => 'after-tls-ok'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->enableTls();

        self::assertSame('after-tls-ok', $replay->readLine());
    }

    public function testCloseAdvancesPastCloseEvent(): void
    {
        // Kills Increment on line 205 + the LogicalAnd / Identical /
        // NotIdentical / negation cluster on line 204: a recorded close
        // event must be consumed by close() so subsequent .events accounting
        // is correct. We assert this by attempting another readLine after
        // close — the close event should NOT remain at the cursor.
        $this->writeJsonl([
            ['t' => 'close'],
            ['t' => 'read_line', 'data' => 'after-close'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->close();

        self::assertSame('after-close', $replay->readLine());
    }

    public function testOpenErrAdvancesCursorPastErrorEvent(): void
    {
        // Kills Increment on line 87 (cursor++ in open_err branch). Mirrors
        // testEnableTlsAdvancesPastTlsErr but for the open lifecycle.
        $this->writeJsonl([
            ['t' => 'open'],
            ['t' => 'open_err', 'message' => 'connect refused'],
            ['t' => 'read_line', 'data' => 'after-open-err'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        try {
            $replay->open('h', 1, Encryption::Tls, 1.0);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // expected
        }

        self::assertSame('after-open-err', $replay->readLine());
    }

    public function testReadBytesGuardRequiresErrorEventType(): void
    {
        // The readBytes error guard combines `t === 'error'` AND
        // `op === 'read_bytes'`. With the LogicalAnd → LogicalOr mutant on
        // line 121, an event with t='wrong' but op='read_bytes' would be
        // (mis)classified as an error and rethrown as ConnectionException
        // instead of falling through to expect() and raising
        // ReplayMismatchException.
        $this->writeJsonl([
            ['t' => 'wrong', 'op' => 'read_bytes', 'message' => 'should not be rethrown'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "read_bytes" but found "wrong"');
        $replay->readBytes(1);
    }

    public function testWriteGuardRequiresErrorEventType(): void
    {
        // Same shape as testReadBytesGuardRequiresErrorEventType — kills the
        // LogicalAnd → LogicalOr mutant on line 163.
        $this->writeJsonl([
            ['t' => 'wrong', 'op' => 'write', 'message' => 'should not be rethrown'],
        ]);

        $replay = new ReplayConnection($this->recordPath);

        $this->expectException(ReplayMismatchException::class);
        $this->expectExceptionMessage('expected "write" but found "wrong"');
        $replay->write("a01 NOOP\r\n");
    }

    public function testEnableTlsLeavesCursorOnNonTlsOkFollow(): void
    {
        // Kills LogicalAnd → LogicalOr on line 197: with `||`, a non-tls_ok
        // follow-up event ($follow !== null but $follow['t'] !== 'tls_ok')
        // would still bump the cursor and skip the event entirely. Original
        // leaves the cursor on the follow-up so a subsequent readLine sees it.
        $this->writeJsonl([
            ['t' => 'tls'],
            ['t' => 'read_line', 'data' => 'after-tls-no-ok'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->enableTls();

        self::assertSame('after-tls-no-ok', $replay->readLine());
    }

    public function testCloseLeavesCursorOnNonCloseFollow(): void
    {
        // Kills LogicalAnd → LogicalOr on line 208 — same shape as the tls
        // case above but for close().
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 'first-after-close'],
            ['t' => 'read_line', 'data' => 'second-after-close'],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->close();

        self::assertSame('first-after-close', $replay->readLine());
        self::assertSame('second-after-close', $replay->readLine());
    }

    public function testStringFieldErrorMessageReportsExactCursor(): void
    {
        // Kills Decrement/Increment/Minus on line 259.
        $this->writeJsonl([
            ['t' => 'read_line', 'data' => 'first'],
            ['t' => 'read_line', 'data' => 42],
        ]);

        $replay = new ReplayConnection($this->recordPath);
        $replay->readLine();

        try {
            $replay->readLine();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('Replay event 1:', $e->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function writeJsonl(array $events): void
    {
        $lines = '';
        foreach ($events as $e) {
            $lines .= json_encode($e) . "\n";
        }
        file_put_contents($this->recordPath, $lines);
    }

    private function captureSession(bool $redact): void
    {
        $inner = new FakeConnection();
        $inner->queueLines('* OK ready');
        $inner->queueBytes('hello world');

        $rec = new RecordingConnection($inner, $this->recordPath, redactCredentials: $redact);
        $rec->open('imap.example.com', 993, Encryption::Tls, 5.0);
        $rec->readLine();
        $rec->readBytes(11);
        $rec->write("a01 NOOP\r\n");
        $rec->close();
    }
}
