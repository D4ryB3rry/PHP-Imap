<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\Redactor;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\Tests\Unit\Support\ThrowingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordingConnection::class)]
#[CoversClass(Redactor::class)]
final class RecordingConnectionTest extends TestCase
{
    private string $recordPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imap-rec-');
        self::assertNotFalse($path);
        $this->recordPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->recordPath)) {
            @unlink($this->recordPath);
        }
    }

    public function testConstructorThrowsWhenPathUnwritable(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to open record file:');

        new RecordingConnection(new FakeConnection(), '/nonexistent-dir-xyz-9f8e/imap.jsonl');
    }

    public function testFullSessionRoundTrip(): void
    {
        $inner = new FakeConnection();
        $inner->queueLines('* OK ready');
        $inner->queueBytes('hello world');

        $rec = new RecordingConnection($inner, $this->recordPath);
        $rec->open('imap.example.com', 993, Encryption::Tls, 5.0);
        $line = $rec->readLine();
        $bytes = $rec->readBytes(11);
        $rec->write("a01 NOOP\r\n");
        $rec->close();

        self::assertSame("* OK ready\r\n", $line);
        self::assertSame('hello world', $bytes);

        $events = $this->readEvents();
        $types = array_map(static fn (array $e): string => $e['t'], $events);
        self::assertSame(
            ['open', 'open_ok', 'read_line', 'read_bytes', 'write', 'close'],
            $types,
        );

        self::assertSame('imap.example.com', $events[0]['host']);
        self::assertSame(993, $events[0]['port']);
        self::assertSame('Tls', $events[0]['encryption']);

        self::assertSame("* OK ready\r\n", $events[2]['data']);
        self::assertSame(11, $events[3]['count']);
        self::assertSame('hello world', base64_decode($events[3]['data'], true));
        self::assertSame("a01 NOOP\r\n", $events[4]['data']);
    }

    public function testWriteRedactsCredentialsByDefault(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        $rec->write("A0001 LOGIN \"alice\" \"hunter2\"\r\n");
        $rec->close();

        // Wire bytes are unchanged.
        self::assertSame(["A0001 LOGIN \"alice\" \"hunter2\"\r\n"], $inner->writes);

        $events = $this->readEvents();
        $writes = array_values(array_filter($events, static fn (array $e): bool => $e['t'] === 'write'));
        self::assertCount(1, $writes);
        self::assertSame("A0001 LOGIN *** ***\r\n", $writes[0]['data']);
    }

    public function testRedactionCanBeDisabled(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath, redactCredentials: false);

        $rec->write("A0001 LOGIN \"alice\" \"hunter2\"\r\n");
        $rec->close();

        $events = $this->readEvents();
        $writes = array_values(array_filter($events, static fn (array $e): bool => $e['t'] === 'write'));
        self::assertSame("A0001 LOGIN \"alice\" \"hunter2\"\r\n", $writes[0]['data']);
    }

    public function testSetReadTimeoutDelegates(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        $rec->setReadTimeout(2.5);
        $rec->close();

        self::assertSame(2.5, $inner->lastReadTimeout);
    }

    public function testIsConnectedDelegates(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        self::assertTrue($rec->isConnected());
        $inner->close();
        self::assertFalse($rec->isConnected());
    }

    public function testEnableTlsRecordsSuccessAndDelegates(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        $rec->enableTls();
        $rec->close();

        self::assertTrue($inner->tlsEnabled);
        $types = array_map(static fn (array $e): string => $e['t'], $this->readEvents());
        self::assertSame(['tls', 'tls_ok', 'close'], $types);
    }

    public function testOpenErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        try {
            $rec->open('imap.example.com', 993, Encryption::Tls, 5.0);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }
        $rec->close();

        $events = $this->readEvents();
        $types = array_map(static fn (array $e): string => $e['t'], $events);
        self::assertSame(['open', 'open_err', 'close'], $types);
        self::assertSame('boom', $events[1]['message']);
    }

    public function testReadLineErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        try {
            $rec->readLine();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }
        $rec->close();

        $err = $this->readEvents()[0];
        self::assertSame('error', $err['t']);
        self::assertSame('read_line', $err['op']);
        self::assertSame('boom', $err['message']);
    }

    public function testReadBytesErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        try {
            $rec->readBytes(7);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }
        $rec->close();

        $err = $this->readEvents()[0];
        self::assertSame('error', $err['t']);
        self::assertSame('read_bytes', $err['op']);
    }

    public function testWriteErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        try {
            $rec->write("a01 NOOP\r\n");
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }
        $rec->close();

        $events = $this->readEvents();
        // First the write event, then the error event.
        $types = array_map(static fn (array $e): string => $e['t'], $events);
        self::assertSame(['write', 'error', 'close'], $types);
        self::assertSame('write', $events[1]['op']);
    }

    public function testEnableTlsErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        try {
            $rec->enableTls();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }
        $rec->close();

        $events = $this->readEvents();
        $types = array_map(static fn (array $e): string => $e['t'], $events);
        self::assertSame(['tls', 'tls_err', 'close'], $types);
        self::assertSame('boom', $events[1]['message']);
    }

    public function testStreamBytesToRecordsAndWritesIntoSink(): void
    {
        $inner = new FakeConnection();
        $inner->queueBytes('hello world');

        $rec = new RecordingConnection($inner, $this->recordPath);

        $sink = fopen('php://memory', 'w+b');
        self::assertNotFalse($sink);

        try {
            $rec->streamBytesTo($sink, 11);

            rewind($sink);
            self::assertSame('hello world', stream_get_contents($sink));
        } finally {
            fclose($sink);
        }
        $rec->close();

        $events = $this->readEvents();
        $reads = array_values(array_filter($events, static fn (array $e): bool => $e['t'] === 'read_bytes'));
        self::assertCount(1, $reads);
        self::assertSame(11, $reads[0]['count']);
        self::assertSame('hello world', base64_decode($reads[0]['data'], true));
    }

    public function testStreamBytesToErrorIsRecordedAndRethrown(): void
    {
        $rec = new RecordingConnection(new ThrowingConnection(), $this->recordPath);

        $sink = fopen('php://memory', 'w+b');
        self::assertNotFalse($sink);

        try {
            $rec->streamBytesTo($sink, 9);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        } finally {
            fclose($sink);
        }
        $rec->close();

        $events = $this->readEvents();
        $err = $events[0];
        self::assertSame('error', $err['t']);
        self::assertSame('read_bytes', $err['op']);
    }

    public function testStreamBytesToThrowsWhenSinkRejectsWrite(): void
    {
        $inner = new FakeConnection();
        $inner->queueBytes('payload');
        $rec = new RecordingConnection($inner, $this->recordPath);

        // A read-only stream causes fwrite() to return 0, tripping the
        // "Failed to write to literal sink" guard.
        $sinkPath = tempnam(sys_get_temp_dir(), 'imap-rec-sink-');
        self::assertNotFalse($sinkPath);
        $sink = fopen($sinkPath, 'rb');
        self::assertNotFalse($sink);

        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Failed to write to literal sink');
            $rec->streamBytesTo($sink, 7);
        } finally {
            restore_error_handler();
            fclose($sink);
            @unlink($sinkPath);
        }
    }

    public function testRecordSilentlyDropsInvalidUtf8(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        // Invalid UTF-8 sequence — JSON_THROW_ON_ERROR fires inside record(),
        // which swallows the exception so logging never crashes the I/O path.
        $rec->write("\xFF\xFE\xFD");
        $rec->close();

        // Wire bytes still went through.
        self::assertSame(["\xFF\xFE\xFD"], $inner->writes);

        // No write event was recorded; only the close event survives.
        $types = array_map(static fn (array $e): string => $e['t'], $this->readEvents());
        self::assertSame(['close'], $types);
    }

    public function testRecordSilentlyDropsAfterCloseHandle(): void
    {
        $inner = new FakeConnection();
        $rec = new RecordingConnection($inner, $this->recordPath);

        $rec->close();
        // After close() the file handle is gone — record() must early-return.
        $rec->write("a01 NOOP\r\n");

        $types = array_map(static fn (array $e): string => $e['t'], $this->readEvents());
        self::assertSame(['close'], $types);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(): array
    {
        $contents = file_get_contents($this->recordPath);
        self::assertNotFalse($contents);

        $events = [];
        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertArrayHasKey('ts', $decoded);
            self::assertArrayHasKey('t', $decoded);
            $events[] = $decoded;
        }

        return $events;
    }
}
