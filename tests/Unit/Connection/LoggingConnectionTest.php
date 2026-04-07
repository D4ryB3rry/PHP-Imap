<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\LoggingConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\Tests\Unit\Support\ThrowingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoggingConnection::class)]
final class LoggingConnectionTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imap-log-');
        self::assertNotFalse($path);
        $this->logPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            @unlink($this->logPath);
        }
    }

    public function testConstructorOpensLogAndWritesSessionStart(): void
    {
        new LoggingConnection(new FakeConnection(), $this->logPath);

        $contents = $this->readLog();
        self::assertStringContainsString('--- session start pid=', $contents);
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}\] --- session start pid=\d+$/m',
            $contents,
        );
    }

    public function testConstructorThrowsWhenLogPathUnwritable(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to open log file:');

        new LoggingConnection(new FakeConnection(), '/nonexistent-dir-xyz-9f8e/imap.log');
    }

    public function testOpenDelegatesAndLogsSuccess(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        $logging->open('imap.example.com', 993, Encryption::Tls, 5.0, ['verify_peer' => false]);

        self::assertTrue($inner->isConnected());
        $contents = $this->readLog();
        self::assertStringContainsString('OPEN imap.example.com:993 encryption=Tls timeout=5.0', $contents);
        self::assertStringContainsString('OPEN ok', $contents);
    }

    public function testOpenLogsErrorAndRethrows(): void
    {
        $logging = new LoggingConnection(new ThrowingConnection(), $this->logPath);

        try {
            $logging->open('imap.example.com', 993, Encryption::Tls, 5.0);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertStringContainsString('OPEN error: boom', $this->readLog());
    }

    public function testReadLineDelegatesLogsAndStripsCrlf(): void
    {
        $inner = new FakeConnection();
        $inner->queueLines('* OK ready');
        $logging = new LoggingConnection($inner, $this->logPath);

        $line = $logging->readLine();

        self::assertSame("* OK ready\r\n", $line);
        $contents = $this->readLog();
        self::assertStringContainsString('S: * OK ready', $contents);
        self::assertStringNotContainsString("S: * OK ready\r", $contents);
    }

    public function testReadLineLogsErrorAndRethrows(): void
    {
        $logging = new LoggingConnection(new ThrowingConnection(), $this->logPath);

        try {
            $logging->readLine();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }

        self::assertStringContainsString('S! boom', $this->readLog());
    }

    public function testReadBytesDelegatesAndLogsPreview(): void
    {
        $inner = new FakeConnection();
        $inner->queueBytes('hello');
        $logging = new LoggingConnection($inner, $this->logPath);

        $data = $logging->readBytes(5);

        self::assertSame('hello', $data);
        self::assertStringContainsString('S< [5 bytes] hello', $this->readLog());
    }

    public function testReadBytesLogsErrorAndRethrows(): void
    {
        $logging = new LoggingConnection(new ThrowingConnection(), $this->logPath);

        try {
            $logging->readBytes(42);
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }

        self::assertStringContainsString('S! readBytes(42): boom', $this->readLog());
    }

    public function testReadBytesPreviewTruncatesAndEscapesNewlines(): void
    {
        $inner = new FakeConnection();
        $payload = str_repeat('a', 100) . "\r\n" . str_repeat('b', 200);
        $inner->queueBytes($payload);
        $logging = new LoggingConnection($inner, $this->logPath);

        $data = $logging->readBytes(strlen($payload));

        self::assertSame($payload, $data);
        $contents = $this->readLog();
        self::assertStringContainsString('\\r\\n', $contents);
        self::assertStringContainsString('…', $contents);
    }

    public function testWriteDelegatesAndLogsCommandWithoutCrlf(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        $logging->write("a01 NOOP\r\n");

        self::assertSame(["a01 NOOP\r\n"], $inner->writes);
        $contents = $this->readLog();
        self::assertStringContainsString('C: a01 NOOP', $contents);
        self::assertStringNotContainsString("C: a01 NOOP\r", $contents);
    }

    public function testWriteLogsErrorAndRethrows(): void
    {
        $logging = new LoggingConnection(new ThrowingConnection(), $this->logPath);

        try {
            $logging->write("a01 NOOP\r\n");
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }

        $contents = $this->readLog();
        self::assertStringContainsString('C: a01 NOOP', $contents);
        self::assertStringContainsString('C! boom', $contents);
    }

    public function testEnableTlsDelegatesAndLogs(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        $logging->enableTls();

        self::assertTrue($inner->tlsEnabled);
        $contents = $this->readLog();
        self::assertStringContainsString('TLS enable', $contents);
        self::assertStringContainsString('TLS ok', $contents);
    }

    public function testEnableTlsLogsErrorAndRethrows(): void
    {
        $logging = new LoggingConnection(new ThrowingConnection(), $this->logPath);

        try {
            $logging->enableTls();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
        }

        self::assertStringContainsString('TLS error: boom', $this->readLog());
    }

    public function testCloseLogsAndClosesInnerAndHandle(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        $logging->close();

        self::assertFalse($inner->isConnected());
        self::assertStringContainsString('--- close', $this->readLog());
    }

    public function testCloseIsIdempotentAndLogIsNoopAfterHandleClosed(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        $logging->close();
        $contentsAfterFirst = $this->readLog();

        // Second close: log handle is no longer a resource, log() must
        // early-return and the is_resource guard in close() must skip fclose().
        $logging->close();

        self::assertSame($contentsAfterFirst, $this->readLog());
    }

    public function testIsConnectedDelegates(): void
    {
        $inner = new FakeConnection();
        $logging = new LoggingConnection($inner, $this->logPath);

        self::assertTrue($logging->isConnected());

        $inner->close();
        self::assertFalse($logging->isConnected());
    }

    private function readLog(): string
    {
        $contents = file_get_contents($this->logPath);
        self::assertNotFalse($contents);

        return $contents;
    }
}
