<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\ReplayConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\ReplayMismatchException;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplayConnection::class)]
#[CoversClass(RecordingConnection::class)]
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
