<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;

class LoggingConnection implements ConnectionInterface
{
    /** @var resource */
    private $logHandle;

    public function __construct(
        private readonly ConnectionInterface $inner,
        string $logPath,
    ) {
        $handle = @fopen($logPath, 'ab');

        if ($handle === false) {
            throw new ConnectionException(sprintf('Failed to open log file: %s', $logPath));
        }

        $this->logHandle = $handle;
        $this->log('---', sprintf('session start pid=%d', getmypid()));
    }

    public function open(string $host, int $port, Encryption $encryption, float $timeout, array $sslOptions = []): void
    {
        $this->log('OPEN', sprintf('%s:%d encryption=%s timeout=%.1f', $host, $port, $encryption->name, $timeout));

        try {
            $this->inner->open($host, $port, $encryption, $timeout, $sslOptions);
            $this->log('OPEN', 'ok');
        } catch (\Throwable $e) {
            $this->log('OPEN', 'error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function readLine(): string
    {
        try {
            $line = $this->inner->readLine();
            $this->log('S:', rtrim($line, "\r\n"));

            return $line;
        } catch (\Throwable $e) {
            $this->log('S!', $e->getMessage());
            throw $e;
        }
    }

    public function readBytes(int $count): string
    {
        try {
            $data = $this->inner->readBytes($count);
            $this->log('S<', sprintf('[%d bytes] %s', $count, $this->preview($data)));

            return $data;
        } catch (\Throwable $e) {
            $this->log('S!', sprintf('readBytes(%d): %s', $count, $e->getMessage()));
            throw $e;
        }
    }

    public function write(string $data): void
    {
        $this->log('C:', rtrim($data, "\r\n"));

        try {
            $this->inner->write($data);
        } catch (\Throwable $e) {
            $this->log('C!', $e->getMessage());
            throw $e;
        }
    }

    public function enableTls(): void
    {
        $this->log('TLS', 'enable');

        try {
            $this->inner->enableTls();
            $this->log('TLS', 'ok');
        } catch (\Throwable $e) {
            $this->log('TLS', 'error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function close(): void
    {
        $this->log('---', 'close');
        $this->inner->close();

        if (is_resource($this->logHandle)) {
            @fclose($this->logHandle);
        }
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    private function log(string $tag, string $message): void
    {
        if (!is_resource($this->logHandle)) {
            return;
        }

        $line = sprintf(
            "[%s] %s %s\n",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            $tag,
            $message,
        );

        @fwrite($this->logHandle, $line);
    }

    private function preview(string $data): string
    {
        $preview = substr($data, 0, 200);
        $preview = str_replace(["\r", "\n"], ['\\r', '\\n'], $preview);

        if (strlen($data) > 200) {
            $preview .= '…';
        }

        return $preview;
    }
}
