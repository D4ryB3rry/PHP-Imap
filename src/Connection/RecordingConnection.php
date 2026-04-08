<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;

/**
 * Decorator that records every I/O operation as a JSONL stream.
 *
 * One JSON object per line. Designed to be consumed by ReplayConnection so that
 * a captured session can drive deterministic end-to-end tests without a real
 * IMAP server.
 *
 * Event schema (each `t` is a single object on its own line):
 *
 *   {"t":"open","ts":"...","host":"...","port":993,"encryption":"Tls","timeout":10.0}
 *   {"t":"open_ok","ts":"..."}
 *   {"t":"open_err","ts":"...","message":"..."}
 *   {"t":"write","ts":"...","data":"A001 LOGIN *** ***\r\n"}
 *   {"t":"read_line","ts":"...","data":"* OK Dovecot ready.\r\n"}
 *   {"t":"read_bytes","ts":"...","count":1234,"data":"<base64>"}
 *   {"t":"tls","ts":"..."}
 *   {"t":"tls_ok","ts":"..."}
 *   {"t":"tls_err","ts":"...","message":"..."}
 *   {"t":"close","ts":"..."}
 *   {"t":"error","ts":"...","op":"read_line","message":"..."}
 *
 * `data` for write/read_line is the raw line including its trailing CRLF.
 * `data` for read_bytes is base64 so binary message bodies survive JSON.
 *
 * SECURITY: when $redactCredentials is true (default) LOGIN/AUTHENTICATE
 * payloads are rewritten before being recorded. The bytes sent to the inner
 * connection are never modified. A recording made with redaction enabled
 * cannot be used to replay an authentication exchange — record with
 * $redactCredentials=false if the fixture must drive an auth test.
 */
class RecordingConnection implements ConnectionInterface
{
    /** @var resource */
    private $recordHandle;

    private readonly Redactor $redactor;

    public function __construct(
        private readonly ConnectionInterface $inner,
        string $recordPath,
        private readonly bool $redactCredentials = true,
    ) {
        $handle = @fopen($recordPath, 'ab');

        if ($handle === false) {
            throw new ConnectionException(sprintf('Failed to open record file: %s', $recordPath));
        }

        $this->recordHandle = $handle;
        $this->redactor = new Redactor();
    }

    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void
    {
        $this->record([
            't' => 'open',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption->name,
            'timeout' => $timeout,
        ]);

        try {
            $this->inner->open($host, $port, $encryption, $timeout, $sslOptions);
            $this->record(['t' => 'open_ok']);
        } catch (\Throwable $e) {
            $this->record(['t' => 'open_err', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function setReadTimeout(float $timeout): void
    {
        $this->inner->setReadTimeout($timeout);
    }

    public function readLine(): string
    {
        try {
            $line = $this->inner->readLine();
            $this->record(['t' => 'read_line', 'data' => $line]);

            return $line;
        } catch (\Throwable $e) {
            $this->record(['t' => 'error', 'op' => 'read_line', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function readBytes(int $count): string
    {
        try {
            $data = $this->inner->readBytes($count);
            $this->record([
                't' => 'read_bytes',
                'count' => strlen($data),
                'data' => base64_encode($data),
            ]);

            return $data;
        } catch (\Throwable $e) {
            $this->record(['t' => 'error', 'op' => 'read_bytes', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function write(string $data): void
    {
        $payload = $data;

        if ($this->redactCredentials) {
            // Apply the redactor to the line stripped of its trailing CRLF, then
            // reattach the framing so the recorded "data" stays a complete wire line.
            $stripped = rtrim($data, "\r\n");
            $redacted = $this->redactor->redact($stripped);

            if ($redacted !== $stripped) {
                $suffix = substr($data, strlen($stripped));
                $payload = $redacted . $suffix;
            }
        }

        $this->record(['t' => 'write', 'data' => $payload]);

        try {
            $this->inner->write($data);
        } catch (\Throwable $e) {
            $this->record(['t' => 'error', 'op' => 'write', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function enableTls(): void
    {
        $this->record(['t' => 'tls']);

        try {
            $this->inner->enableTls();
            $this->record(['t' => 'tls_ok']);
        } catch (\Throwable $e) {
            $this->record(['t' => 'tls_err', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function close(): void
    {
        $this->record(['t' => 'close']);
        $this->inner->close();

        if (is_resource($this->recordHandle)) {
            @fclose($this->recordHandle);
        }
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    /**
     * @param array<string, mixed> $event
     */
    private function record(array $event): void
    {
        if (!is_resource($this->recordHandle)) {
            return;
        }

        $event = ['ts' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z')] + $event;

        try {
            $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return;
        }

        @fwrite($this->recordHandle, $json . "\n");
    }
}
