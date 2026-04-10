<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Connection;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\ReplayMismatchException;

/**
 * Drives the IMAP client from a JSONL recording produced by RecordingConnection.
 *
 * Reads (`read_line`, `read_bytes`) return the recorded server responses.
 * Writes are validated against the recorded client output: by default a
 * mismatch throws ReplayMismatchException; with $strict=false the mismatches
 * are collected into the public $mismatches array instead.
 *
 * `open()`, `enableTls()`, `close()` advance the cursor past the matching
 * lifecycle events. A recorded *_err event causes the corresponding exception
 * to be re-thrown.
 *
 * Credential redaction is handled symmetrically: the same {@see Redactor}
 * that RecordingConnection applied when capturing the session is applied to
 * every incoming write before it is compared against the recorded line. For
 * non-credential lines this is a no-op and the byte-exact check stands; for
 * LOGIN / AUTHENTICATE the redacted forms on both sides match, so a recording
 * captured with $redactCredentials=true can still drive the auth exchange.
 */
class ReplayConnection implements ConnectionInterface
{
    /** @var list<array<string, mixed>> */
    private array $events;

    private int $cursor = 0;

    private bool $connected = true;

    /** @var list<string> */
    public array $mismatches = [];

    private Redactor $recordedRedactor;

    private Redactor $liveRedactor;

    public function __construct(string $recordPath, private bool $strict = true)
    {
        $this->recordedRedactor = new Redactor();
        $this->liveRedactor = new Redactor();
        $contents = @file_get_contents($recordPath);

        if ($contents === false) {
            throw new ConnectionException(sprintf('Failed to read replay file: %s', $recordPath));
        }

        $events = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $index => $line) {
            if ($line === '') {
                continue;
            }

            try {
                // 512 is PHP's default json_decode depth — using a non-default
                // value here would just churn nothing useful, and the depth
                // mutants are not testable without 512-deep nested JSON.
                // @infection-ignore-all
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ConnectionException(sprintf('Invalid JSONL on line %d of %s: %s', $index + 1, $recordPath, $e->getMessage()));
            }

            if (!is_array($decoded) || !isset($decoded['t']) || !is_string($decoded['t'])) {
                throw new ConnectionException(sprintf('Invalid event on line %d of %s', $index + 1, $recordPath));
            }

            /** @var array<string, mixed> $decoded */
            $events[] = $decoded;
        }

        $this->events = $events;
    }

    public function open(string $host, int $port, string $encryption, float $timeout, array $sslOptions = []): void
    {
        $event = $this->expect('open');
        // Optionally lifecycle could be checked here, but the recording is
        // authoritative for replays — we accept whatever host/port the test uses.
        unset($event);

        $follow = $this->peek();

        if ($follow !== null && $follow['t'] === 'open_err') {
            $this->cursor++;
            throw new ConnectionException($this->stringField($follow, 'message'));
        }

        if ($follow !== null && $follow['t'] === 'open_ok') {
            $this->cursor++;
        }

        $this->connected = true;
    }

    public function setReadTimeout(float $timeout): void
    {
        // Replay is deterministic and offline — timeouts have no meaning here.
    }

    public function readLine(): string
    {
        $event = $this->peek();

        if ($event !== null && $event['t'] === 'error' && ($event['op'] ?? null) === 'read_line') {
            $this->cursor++;
            throw new ConnectionException($this->stringField($event, 'message'));
        }

        $event = $this->expect('read_line');

        return $this->stringField($event, 'data');
    }

    public function readBytes(int $count): string
    {
        $event = $this->peek();

        if ($event !== null && $event['t'] === 'error' && ($event['op'] ?? null) === 'read_bytes') {
            $this->cursor++;
            throw new ConnectionException($this->stringField($event, 'message'));
        }

        $event = $this->expect('read_bytes');
        $recordedCount = isset($event['count']) && is_int($event['count']) ? $event['count'] : -1;

        if ($recordedCount !== $count) {
            throw new ReplayMismatchException(sprintf(
                'Replay event %d: readBytes count mismatch (recorded=%d, requested=%d)',
                $this->cursor - 1,
                $recordedCount,
                $count,
            ));
        }

        $data = base64_decode($this->stringField($event, 'data'), true);

        if ($data === false) {
            throw new ConnectionException(sprintf('Replay event %d: read_bytes payload is not valid base64', $this->cursor - 1));
        }

        return $data;
    }

    public function streamBytesTo($sink, int $count): void
    {
        // Replay reads bytes from a JSONL file in memory anyway, so there is
        // nothing to gain by chunking — just buffer-then-write.
        $data = $this->readBytes($count);

        $written = @fwrite($sink, $data);
        // Combined fwrite-failed-or-short-write guard. The LogicalOr → And
        // mutant on this line is observably equivalent in practice — short
        // writes without an outright false return require simulating a
        // partial-write sink, which PHP's stream API does not expose to
        // userland in a portable way.
        // @infection-ignore-all
        if ($written === false || $written !== strlen($data)) {
            throw new ConnectionException('Failed to write to literal sink');
        }
    }

    public function write(string $data): void
    {
        $event = $this->peek();

        if ($event !== null && $event['t'] === 'error' && ($event['op'] ?? null) === 'write') {
            $this->cursor++;
            throw new ConnectionException($this->stringField($event, 'message'));
        }

        $event = $this->expect('write');
        $expected = $this->stringField($event, 'data');

        // Apply the same credential redaction RecordingConnection uses to
        // BOTH the recorded line and the incoming live line. Two independent
        // Redactor instances stay in lock-step over the session, so:
        //   - a recording captured with redaction enabled still matches a
        //     live auth exchange (both sides collapse to "*** ***"),
        //   - a recording captured without redaction still matches a live
        //     replay of the same credentials (both sides collapse the same
        //     way), and
        //   - every non-credential line is unchanged by the Redactor, so
        //     the byte-exact check stands everywhere else.
        $expectedComparable = $this->redactForCompare($this->recordedRedactor, $expected);
        $liveComparable = $this->redactForCompare($this->liveRedactor, $data);

        if ($expectedComparable !== $liveComparable) {
            $message = sprintf(
                'Replay event %d: write mismatch. Expected %s, got %s',
                $this->cursor - 1,
                json_encode($expected),
                json_encode($data),
            );

            if ($this->strict) {
                throw new ReplayMismatchException($message);
            }

            $this->mismatches[] = $message;
        }
    }

    public function enableTls(): void
    {
        $this->expect('tls');
        $follow = $this->peek();

        if ($follow !== null && $follow['t'] === 'tls_err') {
            $this->cursor++;
            throw new ConnectionException($this->stringField($follow, 'message'));
        }

        if ($follow !== null && $follow['t'] === 'tls_ok') {
            $this->cursor++;
        }
    }

    public function close(): void
    {
        $this->connected = false;

        $event = $this->peek();

        if ($event !== null && $event['t'] === 'close') {
            $this->cursor++;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Apply the wire-line Redactor transformation used by RecordingConnection,
     * preserving the trailing CRLF framing so the comparison stays line-for-line.
     */
    private function redactForCompare(Redactor $redactor, string $line): string
    {
        $stripped = rtrim($line, "\r\n");
        $redacted = $redactor->redact($stripped);

        if ($redacted === $stripped) {
            return $line;
        }

        return $redacted . substr($line, strlen($stripped));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peek(): ?array
    {
        return $this->events[$this->cursor] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(string $type): array
    {
        $event = $this->peek();

        if ($event === null) {
            throw new ReplayMismatchException(sprintf(
                'Replay exhausted at event %d: expected "%s" but no events remain',
                $this->cursor,
                $type,
            ));
        }

        if ($event['t'] !== $type) {
            throw new ReplayMismatchException(sprintf(
                'Replay event %d: expected "%s" but found "%s"',
                $this->cursor,
                $type,
                (string) $event['t'],
            ));
        }

        $this->cursor++;

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function stringField(array $event, string $key): string
    {
        $value = $event[$key] ?? null;

        if (!is_string($value)) {
            throw new ConnectionException(sprintf('Replay event %d: field "%s" must be a string', $this->cursor - 1, $key));
        }

        return $value;
    }
}
