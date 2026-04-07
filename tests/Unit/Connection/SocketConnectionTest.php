<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Connection;

use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\TimeoutException;
use D4ry\ImapClient\Tests\Unit\Support\LoopbackServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SocketConnection::class)]
final class SocketConnectionTest extends TestCase
{
    private LoopbackServer $server;

    private SocketConnection $conn;

    protected function setUp(): void
    {
        $this->server = new LoopbackServer();
        $this->conn = new SocketConnection();
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        $this->server->stop();
    }

    // ---------------------------------------------------------------------
    // Pre-open / not-connected behaviour
    // ---------------------------------------------------------------------

    public function testIsConnectedFalseBeforeOpen(): void
    {
        self::assertFalse($this->conn->isConnected());
    }

    public function testReadLineThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to IMAP server');

        $this->conn->readLine();
    }

    public function testReadBytesThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to IMAP server');

        $this->conn->readBytes(10);
    }

    public function testWriteThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to IMAP server');

        $this->conn->write("a01 NOOP\r\n");
    }

    public function testEnableTlsThrowsWhenNotConnected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected to IMAP server');

        $this->conn->enableTls();
    }

    public function testCloseIsNoopWhenNotOpened(): void
    {
        $this->conn->close();
        self::assertFalse($this->conn->isConnected());
    }

    // ---------------------------------------------------------------------
    // open()
    // ---------------------------------------------------------------------

    public function testOpenConnectsViaPlainTcp(): void
    {
        $this->server->start('plain');

        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);

        self::assertTrue($this->conn->isConnected());
        $peer = $this->server->accept();
        self::assertIsResource($peer);
        @fclose($peer);
    }

    public function testOpenWithTlsBuildsSslAddressAndConnects(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $this->server->start('tls');

        $pid = $this->server->forkAccept(function ($peer): void {
            // Hold the TLS session briefly so the parent's handshake completes.
            usleep(200_000);
        });

        try {
            $this->conn->open(
                '127.0.0.1',
                $this->server->port(),
                Encryption::Tls,
                5.0,
                [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            );

            self::assertTrue($this->conn->isConnected());
        } finally {
            $this->server->reap($pid);
        }
    }

    public function testOpenThrowsConnectionExceptionOnFailure(): void
    {
        // Bind a server, capture the port, then stop it so the port is closed.
        $this->server->start('plain');
        $port = $this->server->port();
        $this->server->stop();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/^Failed to connect to 127\.0\.0\.1:' . $port . ' — \[/');

        $this->conn->open('127.0.0.1', $port, Encryption::None, 1.0);
    }

    // ---------------------------------------------------------------------
    // readLine()
    // ---------------------------------------------------------------------

    public function testReadLineReturnsServerLine(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        fwrite($peer, "* OK ready\r\n");
        fflush($peer);

        self::assertSame("* OK ready\r\n", $this->conn->readLine());

        @fclose($peer);
    }

    public function testReadLineThrowsTimeoutWhenServerSilent(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 0.2);
        $peer = $this->server->accept();

        try {
            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessage('Socket read timed out');
            $this->conn->readLine();
        } finally {
            @fclose($peer);
        }
    }

    public function testReadLineThrowsConnectionExceptionWhenFgetsFails(): void
    {
        // To exercise the post-assertConnected fgets-failure branch we need
        // a stream where fgets() returns false but feof() is still false.
        // A non-blocking socket with no data ready satisfies both conditions
        // (PHP's stream layer flips feof to true as soon as a peer FIN is
        // observed, so a closed-peer scenario is caught by assertConnected
        // first and never reaches that branch).
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        $stream = $this->getInternalStream();
        stream_set_blocking($stream, false);

        try {
            $this->conn->readLine();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('Failed to read from socket', $e->getMessage());
        }

        @fclose($peer);
    }

    public function testIsConnectedFalseAfterPeerClose(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();
        @fclose($peer);

        // Wait for FIN — PHP's stream layer flips feof() to true.
        usleep(50_000);

        self::assertFalse($this->conn->isConnected());
    }

    // ---------------------------------------------------------------------
    // readBytes()
    // ---------------------------------------------------------------------

    public function testReadBytesAccumulatesAcrossChunks(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $this->server->start('plain');

        $pid = $this->server->forkAccept(function ($peer): void {
            fwrite($peer, 'abc');
            fflush($peer);
            usleep(100_000);
            fwrite($peer, 'def');
            fflush($peer);
            usleep(100_000);
        });

        try {
            $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 2.0);
            self::assertSame('abcdef', $this->conn->readBytes(6));
        } finally {
            $this->server->reap($pid);
        }
    }

    public function testReadBytesThrowsTimeout(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 0.2);
        $peer = $this->server->accept();

        try {
            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessage('Socket read timed out');
            $this->conn->readBytes(10);
        } finally {
            @fclose($peer);
        }
    }

    public function testReadBytesThrowsConnectionExceptionWhenFreadFails(): void
    {
        // Same rationale as the readLine variant above — non-blocking with no
        // data ready makes fread() return '' while feof() stays false.
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        stream_set_blocking($this->getInternalStream(), false);

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Failed to read from socket');
            $this->conn->readBytes(10);
        } finally {
            @fclose($peer);
        }
    }

    // ---------------------------------------------------------------------
    // write()
    // ---------------------------------------------------------------------

    public function testWriteSendsAllBytes(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        $this->conn->write("a01 NOOP\r\n");

        // Read on server side to verify.
        $received = '';
        stream_set_timeout($peer, 1);
        while (strlen($received) < 10) {
            $chunk = fread($peer, 10 - strlen($received));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $received .= $chunk;
        }
        self::assertSame("a01 NOOP\r\n", $received);

        @fclose($peer);
    }

    public function testWriteThrowsWhenLocalWriteSideShutdown(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        // Reach into the SocketConnection and shut down its write side. The
        // stream is still non-null and not at feof, so assertConnected() will
        // pass — but the next fwrite() will return false, exercising the
        // post-assertConnected failure branch on line 109–110.
        $stream = $this->getInternalStream();
        stream_socket_shutdown($stream, STREAM_SHUT_WR);

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Failed to write to socket');
            $this->conn->write('hello');
        } finally {
            @fclose($peer);
        }
    }

    // ---------------------------------------------------------------------
    // enableTls()
    // ---------------------------------------------------------------------

    public function testEnableTlsUpgradesPlainSocket(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $this->server->start('starttls');

        $pid = $this->server->forkAccept(function ($peer): void {
            @stream_socket_enable_crypto(
                $peer,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
                | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
                | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
            );
            usleep(200_000);
        });

        try {
            // Disable verification on the upgrade — the SSL options set during
            // open() persist on the stream and would otherwise reject the
            // self-signed loopback cert.
            $this->conn->open(
                '127.0.0.1',
                $this->server->port(),
                Encryption::None,
                5.0,
                [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            );
            $this->conn->enableTls();
            self::assertTrue($this->conn->isConnected());
        } finally {
            $this->server->reap($pid);
        }
    }

    public function testEnableTlsThrowsWhenServerNotTls(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $this->server->start('plain');

        // Server replies with non-TLS garbage so the client's TLS handshake
        // fails on a parse error rather than EOF (a clean close trips
        // assertConnected first via PHP's eager feof()).
        $pid = $this->server->forkAccept(function ($peer): void {
            fwrite($peer, str_repeat("\x00\x01\x02\x03", 256));
            fflush($peer);
            usleep(300_000);
        });

        try {
            $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);

            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Failed to enable TLS on socket');
            $this->conn->enableTls();
        } finally {
            $this->server->reap($pid);
        }
    }

    // ---------------------------------------------------------------------
    // close() / isConnected() / private branches
    // ---------------------------------------------------------------------

    public function testCloseIsIdempotent(): void
    {
        $this->server->start('plain');
        $this->conn->open('127.0.0.1', $this->server->port(), Encryption::None, 1.0);
        $peer = $this->server->accept();

        $this->conn->close();
        self::assertFalse($this->conn->isConnected());

        // Second close exercises the $stream !== null false branch (line 130).
        $this->conn->close();
        self::assertFalse($this->conn->isConnected());

        @fclose($peer);
    }

    public function testIsTimedOutReturnsFalseWhenStreamIsNull(): void
    {
        // The stream-null branch in isTimedOut() (lines 150–152) is unreachable
        // through the public API because assertConnected() rejects unconnected
        // calls first. Exercise it directly so coverage reflects intent.
        $method = new \ReflectionMethod(SocketConnection::class, 'isTimedOut');
        self::assertFalse($method->invoke($this->conn));
    }

    /**
     * @return resource
     */
    private function getInternalStream()
    {
        $stream = (new ReflectionProperty(SocketConnection::class, 'stream'))->getValue($this->conn);
        self::assertIsResource($stream);

        return $stream;
    }
}
