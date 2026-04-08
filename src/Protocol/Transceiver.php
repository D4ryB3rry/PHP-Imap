<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol;

use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Protocol\Command\Command;
use D4ry\ImapClient\Protocol\Contract\TransceiverInterface;
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

    public function commandRaw(string $rawLine): Response
    {
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

    public function sendAuthenticateCommand(string $mechanism): Response
    {
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
