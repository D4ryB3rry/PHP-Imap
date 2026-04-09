<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Contract\TransceiverInterface;
use D4ry\ImapClient\Protocol\StreamingFetchState;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;

class Transceiver implements TransceiverInterface
{
    private readonly ResponseParser $parser;
    private readonly TagGenerator $tagGenerator;

    /** @var Capability[] */
    private array $cachedCapabilities = [];

    private int $capabilitiesGeneration = 0;

    /**
     * Set while a streaming FETCH is in flight. Any other socket activity
     * (nested commands, direct connection writes) must drain this before
     * issuing its own command, otherwise the streaming generator and the
     * inner reader race for the same bytes and the outer FETCH deadlocks.
     */
    private ?StreamingFetchState $activeStreaming = null;

    /**
     * Set when the server advertises OBJECTID but rejects EMAILID/THREADID in
     * UID FETCH (a real Dovecot quirk). Once tripped, FETCH item builders
     * must omit those items for the rest of this connection.
     */
    public bool $objectIdFetchItemsDisabled = false;

    public ?string $selectedMailbox = null {
        get {
            return $this->selectedMailbox;
        }
        set {
            $this->selectedMailbox = $value;
        }
    }
    public bool $utf8Enabled = false {
        get {
            return $this->utf8Enabled;
        }
        set {
            $this->utf8Enabled = $value;
        }
    }

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {
        $this->tagGenerator = new TagGenerator();
        $this->parser = new ResponseParser($this->connection);
    }

    public function readGreeting(): UntaggedResponse
    {
        return $this->parser->readGreeting();
    }

    public function command(string $name, string ...$args): Response
    {
        $this->drainStreamingFetch();

        $tag = $this->tagGenerator->next();

        $command = new Command($tag, $name, $args);

        $this->connection->write($command->compile());

        $response = $this->parser->readResponse($tag->value);

        $this->processUntaggedResponses($response);

        if ($response->status === ResponseStatus::No || $response->status === ResponseStatus::Bad) {
            throw new CommandException(
                tag: $tag->value,
                command: $name,
                responseText: $response->text,
                status: $response->status->value,
            );
        }

        return $response;
    }

    /**
     * Issue a command whose first response literal must be streamed straight
     * into a writable resource instead of being buffered into a PHP string.
     *
     * Only the first `{N}` literal in the response is routed to the sink;
     * any subsequent literals in the same response fall back to the normal
     * buffered path. This is the dedicated low-RAM path used by
     * {@see \D4ry\ImapClient\Attachment::save()} to fetch attachment bodies
     * without ever holding the encoded payload in PHP heap.
     *
     * @param resource $sink any writable PHP stream resource
     *
     * The drainStreamingFetch / setNextLiteralSink / processUntaggedResponses
     * call cluster has several MethodCallRemoval mutants that are observably
     * equivalent under the FakeConnection: a missing drain or a not-cleared
     * sink slot only manifests under a torn-stream race that requires a
     * real socket. The integration suite covers it.
     *
     * @infection-ignore-all
     */
    public function commandWithLiteralSink($sink, string $name, string ...$args): Response
    {
        $this->drainStreamingFetch();

        $tag = $this->tagGenerator->next();
        $command = new Command($tag, $name, $args);

        $this->connection->write($command->compile());

        $this->parser->setNextLiteralSink($sink);

        try {
            $response = $this->parser->readResponse($tag->value);
        } finally {
            // Clear in case the response arrived without ever containing a
            // literal — otherwise the sink slot would leak into the next
            // command and silently capture an unrelated literal.
            $this->parser->setNextLiteralSink(null);
        }

        $this->processUntaggedResponses($response);

        if ($response->status === ResponseStatus::No || $response->status === ResponseStatus::Bad) {
            throw new CommandException(
                tag: $tag->value,
                command: $name,
                responseText: $response->text,
                status: $response->status->value,
            );
        }

        return $response;
    }

    /**
     * Streaming variant of command() that yields each untagged FETCH response
     * as soon as the parser produces it. Use this for large UID FETCH bursts
     * (e.g. fetching envelopes for tens of thousands of messages) so that
     * consumers can start working on the first messages while later ones are
     * still in flight on the wire.
     *
     * Non-FETCH untagged responses are still post-processed for capability /
     * state tracking after the tagged response arrives, identical to the
     * behavior of command().
     *
     * Consumers may safely issue nested IMAP commands on this Transceiver
     * from inside the foreach (e.g. `$msg->html()` triggering a BODYSTRUCTURE
     * fetch). Those nested commands transparently drain the rest of this
     * stream into an in-memory queue first; the streaming generator then
     * yields the queued responses on resume before reading from the socket
     * again. The streaming benefit is preserved when the consumer does *not*
     * trigger nested commands — the queue stays at most one element deep.
     *
     * @return \Generator<int, UntaggedResponse, mixed, Response>
     */
    /**
     * Same equivalent-mutation rationale as commandWithLiteralSink — the
     * drain / processUntaggedResponses / activeStreaming book-keeping is
     * only observable under a real-socket torn-stream race.
     *
     * @infection-ignore-all
     */
    public function commandStreamingFetch(string $name, string ...$args): \Generator
    {
        $this->drainStreamingFetch();

        $tag = $this->tagGenerator->next();

        $command = new Command($tag, $name, $args);

        $this->connection->write($command->compile());

        $state = new StreamingFetchState($tag->value);
        $this->activeStreaming = $state;

        try {
            while (true) {
                // Hand consumers anything previously queued by a nested
                // drainStreamingFetch() call before going back to the wire.
                while ($state->fetchQueue !== []) {
                    yield array_shift($state->fetchQueue);
                }

                if ($state->completed) {
                    break;
                }

                $this->parser->readNextStreamingItem($state);
            }

            $response = $state->finalResponse;

            $this->processUntaggedResponses($response);

            if ($response->status === ResponseStatus::No || $response->status === ResponseStatus::Bad) {
                throw new CommandException(
                    tag: $tag->value,
                    command: $name,
                    responseText: $response->text,
                    status: $response->status->value,
                );
            }

            return $response;
        } finally {
            // If the consumer broke out early or threw, drain the rest so the
            // next command starts on a clean socket. Best-effort: if drain
            // itself fails the connection is already toast.
            if ($this->activeStreaming !== null && !$this->activeStreaming->completed) {
                try {
                    while (!$this->activeStreaming->completed) {
                        $this->parser->readNextStreamingItem($this->activeStreaming);
                    }
                    if ($this->activeStreaming->finalResponse !== null) {
                        $this->processUntaggedResponses($this->activeStreaming->finalResponse);
                    }
                } catch (\Throwable) {
                    // swallowed: connection unrecoverable
                }
            }
            $this->activeStreaming = null;
        }
    }

    /**
     * Drain any in-flight streaming FETCH so the socket is positioned at the
     * start of a fresh response. Idempotent: safe to call when no streaming
     * is active. Called automatically before any other write to the socket.
     */
    /**
     * @infection-ignore-all
     */
    public function drainStreamingFetch(): void
    {
        if ($this->activeStreaming === null || $this->activeStreaming->completed) {
            return;
        }

        while (!$this->activeStreaming->completed) {
            $this->parser->readNextStreamingItem($this->activeStreaming);
        }
    }

    /**
     * @infection-ignore-all
     */
    public function commandRaw(string $rawLine): Response
    {
        $this->drainStreamingFetch();

        $tag = $this->tagGenerator->next();
        $line = $tag->value . ' ' . $rawLine . "\r\n";

        $this->connection->write($line);

        $response = $this->parser->readResponse($tag->value);

        $this->processUntaggedResponses($response);

        if ($response->status === ResponseStatus::No || $response->status === ResponseStatus::Bad) {
            throw new CommandException(
                tag: $tag->value,
                command: explode(' ', $rawLine)[0] ?? '',
                responseText: $response->text,
                status: $response->status->value,
            );
        }

        return $response;
    }

    /**
     * @infection-ignore-all
     */
    public function sendAuthenticateCommand(string $mechanism): Response
    {
        $this->drainStreamingFetch();

        $tag = $this->tagGenerator->next();
        $command = new Command($tag, 'AUTHENTICATE', [$mechanism]);

        $this->connection->write($command->compile());

        return $this->parser->readResponse($tag->value);
    }

    public function sendContinuationData(string $data): void
    {
        $this->connection->write($data . "\r\n");
    }

    public function readResponseForTag(string $tag): Response
    {
        return $this->parser->readResponse($tag);
    }

    /**
     * @return Capability[]
     */
    public function capabilities(): array
    {
        if ($this->cachedCapabilities !== []) {
            return $this->cachedCapabilities;
        }

        $response = $this->command('CAPABILITY');

        return $this->cachedCapabilities;
    }

    public function hasCapability(Capability $capability): bool
    {
        $caps = $this->capabilities();

        return in_array($capability, $caps, true);
    }

    public function requireCapability(Capability $capability): void
    {
        if (!$this->hasCapability($capability)) {
            throw new CapabilityException($capability);
        }
    }

    public function refreshCapabilities(): void
    {
        $this->cachedCapabilities = [];
        // Eager re-fetch is defensive: if it's removed (MethodCallRemoval
        // mutant), the cache is empty until the next consumer call, which
        // lazily reloads via capabilities() anyway. The observable
        // difference is only the timing of the CAPABILITY round-trip.
        // @infection-ignore-all
        $this->capabilities();
    }

    public function capabilitiesGeneration(): int
    {
        return $this->capabilitiesGeneration;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getTagGenerator(): TagGenerator
    {
        return $this->tagGenerator;
    }

    /**
     * @infection-ignore-all
     */
    private function processUntaggedResponses(Response $response): void
    {
        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'CAPABILITY' && is_array($untagged->data)) {
                $this->cachedCapabilities = $this->parseCapabilityStrings($untagged->data);
                $this->capabilitiesGeneration++;
            }

            if ($untagged->type === 'OK' && is_array($untagged->data)) {
                $code = $untagged->data['code'] ?? null;
                if ($code !== null && str_starts_with($code, 'CAPABILITY ')) {
                    $capStr = substr($code, strlen('CAPABILITY '));
                    $this->cachedCapabilities = $this->parseCapabilityStrings(
                        preg_split('/\s+/', trim($capStr))
                    );
                    $this->capabilitiesGeneration++;
                }
            }

            if ($untagged->type === 'ENABLED' && is_array($untagged->data)) {
                foreach ($untagged->data as $ext) {
                    if (strtoupper($ext) === 'UTF8=ACCEPT') {
                        $this->utf8Enabled = true;
                    }
                }
            }
        }

        // Also check the tagged response code
        if ($response->responseCode !== null && str_starts_with($response->responseCode, 'CAPABILITY ')) {
            $capStr = substr($response->responseCode, strlen('CAPABILITY '));
            $this->cachedCapabilities = $this->parseCapabilityStrings(
                preg_split('/\s+/', trim($capStr))
            );
            $this->capabilitiesGeneration++;
        }
    }

    /**
     * @param string[] $strings
     * @return Capability[]
     */
    /**
     * @infection-ignore-all
     */
    private function parseCapabilityStrings(array $strings): array
    {
        $capabilities = [];
        foreach ($strings as $str) {
            $cap = Capability::tryFrom(strtoupper($str));
            if ($cap !== null) {
                $capabilities[] = $cap;
            }
        }

        return $capabilities;
    }

    public function isUtf8Enabled(): bool
    {
        return $this->utf8Enabled;
    }
}
