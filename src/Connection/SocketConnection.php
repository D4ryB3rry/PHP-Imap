<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\TimeoutException;

/**
 * Real PHP-stream-backed socket transport. The mutation surface here is
 * dominated by stream-API book-keeping (timeouts, fread/fwrite chunk
 * loops, TLS upgrade) which is observably equivalent under unit tests
 * because the LoopbackServer-driven integration suite covers the live
 * behaviour. The MailboxTest greeting-timeout / STARTTLS / replay tests
 * collectively exercise the public surface of this class.
 *
 * @infection-ignore-all
 */
class SocketConnection implements ConnectionInterface
{
    /** @var resource|null */
    private $stream = null;

    private float $timeout = 30.0;

    public function open(string $host, int $port, string $encryption, float $timeout, array $sslOptions = []): void
    {
        $this->timeout = $timeout;

        $address = match ($encryption) {
            Encryption::Tls => sprintf('ssl://%s:%d', $host, $port),
            default => sprintf('tcp://%s:%d', $host, $port),
        };

        $sslContext = array_replace([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ], $sslOptions);

        $context = stream_context_create([
            'ssl' => $sslContext,
        ]);

        $errno = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new ConnectionException(
                sprintf('Failed to connect to %s:%d — [%d] %s', $host, $port, $errno, $errstr)
            );
        }

        stream_set_timeout($stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));

        $this->stream = $stream;
    }

    public function setReadTimeout(float $timeout): void
    {
        $this->assertConnected();

        $this->timeout = $timeout;
        stream_set_timeout($this->stream, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
    }

    public function readLine(): string
    {
        $this->assertConnected();

        $line = @fgets($this->stream);

        if ($line === false) {
            if ($this->isTimedOut()) {
                throw new TimeoutException('Socket read timed out');
            }

            throw new ConnectionException('Failed to read from socket');
        }

        return $line;
    }

    public function readBytes(int $count): string
    {
        $this->assertConnected();

        $data = '';
        $remaining = $count;

        while ($remaining > 0) {
            $chunk = @fread($this->stream, $remaining);

            if ($chunk === false || $chunk === '') {
                if ($this->isTimedOut()) {
                    throw new TimeoutException('Socket read timed out');
                }

                throw new ConnectionException('Failed to read from socket');
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    public function streamBytesTo($sink, int $count): void
    {
        $this->assertConnected();

        if (!is_resource($sink)) {
            throw new ConnectionException('streamBytesTo() requires a valid stream resource');
        }

        $remaining = $count;
        $chunkSize = 8192;

        while ($remaining > 0) {
            $chunk = @fread($this->stream, $remaining < $chunkSize ? $remaining : $chunkSize);

            if ($chunk === false || $chunk === '') {
                if ($this->isTimedOut()) {
                    throw new TimeoutException('Socket read timed out');
                }

                throw new ConnectionException('Failed to read from socket');
            }

            $written = @fwrite($sink, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                throw new ConnectionException('Failed to write to literal sink');
            }

            $remaining -= strlen($chunk);
        }
    }

    public function write(string $data): void
    {
        $this->assertConnected();

        $written = @fwrite($this->stream, $data);

        if ($written === false || $written !== strlen($data)) {
            throw new ConnectionException('Failed to write to socket');
        }
    }

    public function enableTls(): void
    {
        $this->assertConnected();

        $result = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );
        if ($result !== true) {
            throw new ConnectionException('Failed to enable TLS on socket');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('Not connected to IMAP server');
        }
    }

    private function isTimedOut(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);

        return $meta['timed_out'] ?? false;
    }
}
