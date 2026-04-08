<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Connection\Contract\ConnectionInterface;
use D4ry\ImapClient\Connection\LoggingConnection;
use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\ReplayConnection;
use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Contract\MailboxInterface;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Idle\FlagsChangedEvent;
use D4ry\ImapClient\Idle\IdleEvent;
use D4ry\ImapClient\Idle\IdleHandlerInterface;
use D4ry\ImapClient\Idle\IdleHeartbeatEvent;
use D4ry\ImapClient\Idle\MessageExpungedEvent;
use D4ry\ImapClient\Idle\MessageReceivedEvent;
use D4ry\ImapClient\Idle\RecentCountEvent;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\NamespaceInfo;

readonly class Mailbox implements MailboxInterface
{
    private Transceiver $transceiver;

    private function __construct(Transceiver $transceiver)
    {
        $this->transceiver = $transceiver;
    }

    public static function connect(Config $config): self
    {
        $connection = new SocketConnection();

        if ($config->logPath !== null) {
            $connection = new LoggingConnection($connection, $config->logPath);
        }

        if ($config->recordPath !== null) {
            $connection = new RecordingConnection($connection, $config->recordPath, $config->recordRedactCredentials);
        }

        $connection->open($config->host, $config->port, $config->encryption, $config->timeout, $config->sslOptions);

        return self::performHandshake($connection, $config);
    }

    /**
     * Build a Mailbox by replaying a previously captured session from disk.
     *
     * Uses {@see ReplayConnection} as the I/O backend instead of a real socket.
     * The full connect lifecycle still runs (greeting, optional STARTTLS,
     * authenticate, ENABLE, ID) — every outbound write is validated against
     * the recording, so the supplied $config must match the credentials and
     * feature flags that were used when the session was recorded. Mismatches
     * raise {@see \D4ry\ImapClient\Exception\ReplayMismatchException}.
     *
     * The host/port/encryption/timeout/sslOptions fields of $config are
     * passed through but have no real effect, since no socket is opened.
     */
    public static function connectFromRecording(string $recordPath, Config $config): self
    {
        $connection = new ReplayConnection($recordPath);
        $connection->open($config->host, $config->port, $config->encryption, $config->timeout, $config->sslOptions);

        return self::performHandshake($connection, $config);
    }

    private static function performHandshake(ConnectionInterface $connection, Config $config): self
    {
        $transceiver = new Transceiver($connection);

        // Read the greeting with a deliberately tight timeout. If the server
        // doesn't speak in time it's almost always an encryption mismatch
        // (e.g. StartTls against an implicit-TLS port — the server is waiting
        // for a TLS ClientHello and will never send the plaintext "* OK"),
        // so failing fast with a diagnostic is much friendlier than blocking
        // for the full $config->timeout window.
        $greetingTimeout = min($config->greetingTimeout, $config->timeout);
        $connection->setReadTimeout($greetingTimeout);

        try {
            $greeting = $transceiver->readGreeting();
        } catch (\D4ry\ImapClient\Exception\TimeoutException $e) {
            $connection->close();
            throw new \D4ry\ImapClient\Exception\ConnectionException(
                self::buildGreetingTimeoutHint($config, $greetingTimeout),
                previous: $e,
            );
        }

        $connection->setReadTimeout($config->timeout);

        // STARTTLS if needed
        if ($config->encryption === Encryption::StartTls) {
            // Transceiver::command() throws CommandException on NO/BAD, so a
            // returned response is always OK by construction.
            $transceiver->command('STARTTLS');
            $connection->enableTls();
            $transceiver->refreshCapabilities();
        }

        // Authenticate. Per RFC 3501 §6.2, the capability list can change
        // across authentication, so we must re-issue CAPABILITY unless the
        // server already volunteered an updated set via a [CAPABILITY ...]
        // response code on the LOGIN/AUTHENTICATE OK reply. We detect that by
        // watching the Transceiver's capability generation counter.
        $capsGenerationBefore = $transceiver->capabilitiesGeneration();
        $config->credential->authenticate($transceiver);
        if ($transceiver->capabilitiesGeneration() === $capsGenerationBefore) {
            $transceiver->refreshCapabilities();
        }

        // Enable extensions after authentication
        $enableExtensions = [];

        if ($config->enableCondstore && $transceiver->hasCapability(Capability::Enable)) {
            $enableExtensions[] = 'CONDSTORE';
        }

        if ($config->enableQresync && $transceiver->hasCapability(Capability::Enable)) {
            $enableExtensions[] = 'QRESYNC';
        }

        if ($config->utf8Accept && $transceiver->hasCapability(Capability::Utf8Accept)) {
            $enableExtensions[] = 'UTF8=ACCEPT';
        }

        if ($enableExtensions !== [] && $transceiver->hasCapability(Capability::Enable)) {
            $transceiver->command('ENABLE', implode(' ', $enableExtensions));
        }

        // Send ID command if client params provided
        if ($config->clientId !== null && $transceiver->hasCapability(Capability::Id)) {
            $params = [];
            foreach ($config->clientId as $key => $value) {
                $params[] = '"' . $key . '"';
                $params[] = '"' . $value . '"';
            }
            $transceiver->command('ID', '(' . implode(' ', $params) . ')');
        }

        return new self($transceiver);
    }

    /**
     * Build the human-readable hint shown when the server doesn't deliver an
     * IMAP greeting in time. The hint suggests which Encryption mode is most
     * likely to work, based on what the user just tried.
     */
    private static function buildGreetingTimeoutHint(Config $config, float $waited): string
    {
        $base = sprintf(
            'No IMAP greeting from %s:%d within %.1fs (encryption=%s).',
            $config->host,
            $config->port,
            $waited,
            $config->encryption->name,
        );

        $suggestion = match ($config->encryption) {
            Encryption::StartTls, Encryption::None =>
                ' The server accepted the TCP connection but never sent a plaintext "* OK ..." line.'
                . ' This usually means the port is implicit-TLS — try Encryption::Tls.',
            Encryption::Tls =>
                ' The TLS handshake completed but the server never sent an IMAP greeting.'
                . ' The port may not be implicit-TLS — try Encryption::StartTls or Encryption::None,'
                . ' or check that the port actually speaks IMAP.',
        };

        return $base . $suggestion;
    }

    public function folders(): FolderCollection
    {
        return new FolderCollection(function (): array {
            $response = $this->transceiver->command('LIST', '""', '"*"');

            return $this->parseFolderList($response->untagged);
        });
    }

    public function folder(string $path): FolderInterface
    {
        $encoded = CommandBuilder::encodeMailboxName($path, $this->transceiver->isUtf8Enabled());
        $response = $this->transceiver->command('LIST', '""', $encoded);

        $folders = $this->parseFolderList($response->untagged);

        if ($folders === []) {
            // Return a folder object even if not found via LIST (user might CREATE it)
            return new Folder(
                transceiver: $this->transceiver,
                mailboxPath: new MailboxPath($path),
            );
        }

        return $folders[0];
    }

    public function inbox(): FolderInterface
    {
        return $this->folder('INBOX');
    }

    /**
     * @return Capability[]
     */
    public function capabilities(): array
    {
        return $this->transceiver->capabilities();
    }

    public function hasCapability(Capability $capability): bool
    {
        return $this->transceiver->hasCapability($capability);
    }

    public function id(?array $clientParams = null): ?array
    {
        $this->transceiver->requireCapability(Capability::Id);

        if ($clientParams === null) {
            $response = $this->transceiver->command('ID', 'NIL');
        } else {
            $params = [];
            foreach ($clientParams as $key => $value) {
                $params[] = '"' . $key . '"';
                $params[] = '"' . $value . '"';
            }
            $response = $this->transceiver->command('ID', '(' . implode(' ', $params) . ')');
        }

        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'ID') {
                return is_array($untagged->data) ? $untagged->data : null;
            }
        }

        return null;
    }

    public function namespace(): NamespaceInfo
    {
        $this->transceiver->requireCapability(Capability::Namespace);

        $response = $this->transceiver->command('NAMESPACE');

        // Parse NAMESPACE response — simplified, returns raw for now
        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'NAMESPACE') {
                return $this->parseNamespaceResponse($untagged->data);
            }
        }

        return new NamespaceInfo();
    }

    public function idle(IdleHandlerInterface|callable $handler, float $timeout = 300): void
    {
        $this->transceiver->requireCapability(Capability::Idle);

        $tag = $this->transceiver->getTagGenerator()->next();
        $this->transceiver->getConnection()->write($tag->value . " IDLE\r\n");

        // Read continuation response
        $line = $this->transceiver->getConnection()->readLine();
        if (!str_starts_with(trim($line), '+')) {
            throw new ConnectionException('IDLE command not accepted by server');
        }

        $startTime = microtime(true);

        while (microtime(true) - $startTime < $timeout) {
            try {
                $line = $this->transceiver->getConnection()->readLine();
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                $event = $this->parseIdleEvent($line);

                if ($event === null) {
                    continue;
                }

                $shouldContinue = $this->dispatchIdleEvent($handler, $event);

                if ($shouldContinue === false) {
                    break;
                }
            } catch (\D4ry\ImapClient\Exception\TimeoutException) {
                continue;
            }
        }

        // Send DONE to terminate IDLE
        $this->transceiver->getConnection()->write("DONE\r\n");
        $this->transceiver->readResponseForTag($tag->value);
    }

    private function parseIdleEvent(string $line): ?IdleEvent
    {
        if (!str_starts_with($line, '* ')) {
            return null;
        }

        $rest = substr($line, 2);

        // * N EXISTS / * N EXPUNGE / * N RECENT / * N FETCH
        if (preg_match('/^(\d+)\s+(\w+)(.*)$/', $rest, $matches)) {
            $number = (int) $matches[1];
            $type = strtoupper($matches[2]);
            $extra = trim($matches[3]);

            return match ($type) {
                'EXISTS' => new MessageReceivedEvent($line, $number),
                'EXPUNGE' => new MessageExpungedEvent($line, $number),
                'RECENT' => new RecentCountEvent($line, $number),
                'FETCH' => $this->parseFetchIdleEvent($line, $number, $extra),
                default => new IdleHeartbeatEvent($line, $rest),
            };
        }

        // * OK Still here
        if (preg_match('/^(OK|NO|BAD|BYE)\s+(.*)$/i', $rest, $matches)) {
            return new IdleHeartbeatEvent($line, trim($matches[2]));
        }

        return new IdleHeartbeatEvent($line, $rest);
    }

    private function parseFetchIdleEvent(string $line, int $sequenceNumber, string $data): IdleEvent
    {
        // Parse (FLAGS (\Seen \Flagged))
        if (preg_match('/\(FLAGS\s+\(([^)]*)\)\)/', $data, $matches)) {
            $flagStrings = $matches[1] !== '' ? preg_split('/\s+/', trim($matches[1])) : [];

            return new FlagsChangedEvent(
                $line,
                $sequenceNumber,
                new \D4ry\ImapClient\ValueObject\FlagSet($flagStrings),
            );
        }

        return new IdleHeartbeatEvent($line, "FETCH $data");
    }

    private function dispatchIdleEvent(IdleHandlerInterface|callable $handler, IdleEvent $event): bool
    {
        if ($handler instanceof IdleHandlerInterface) {
            return match (true) {
                $event instanceof MessageReceivedEvent => $handler->onMessageReceived($event),
                $event instanceof MessageExpungedEvent => $handler->onMessageExpunged($event),
                $event instanceof FlagsChangedEvent => $handler->onFlagsChanged($event),
                $event instanceof RecentCountEvent => $handler->onRecentCount($event),
                $event instanceof IdleHeartbeatEvent => $handler->onHeartbeat($event),
                default => true,
            };
        }

        return $handler($event) !== false;
    }

    public function disconnect(): void
    {
        try {
            $this->transceiver->command('LOGOUT');
        } catch (\Exception) {
            // Best effort
        }

        $this->transceiver->getConnection()->close();
    }

    public function getTransceiver(): Transceiver
    {
        return $this->transceiver;
    }

    /**
     * @param \D4ry\ImapClient\Protocol\Response\UntaggedResponse[] $untaggedResponses
     * @return FolderInterface[]
     */
    private function parseFolderList(array $untaggedResponses): array
    {
        $folders = [];

        foreach ($untaggedResponses as $untagged) {
            if ($untagged->type !== 'LIST' || !is_array($untagged->data)) {
                continue;
            }

            $data = $untagged->data;
            $attrs = $data['attributes'] ?? [];
            $delimiter = $data['delimiter'] ?? '/';
            $rawName = $data['name'] ?? '';

            if ($rawName === '') {
                continue;
            }

            $name = CommandBuilder::decodeMailboxName($rawName, $this->transceiver->isUtf8Enabled());

            $specialUse = null;
            foreach ($attrs as $attr) {
                $specialUse = SpecialUse::tryFrom($attr);
                if ($specialUse !== null) {
                    break;
                }
            }

            $folders[] = new Folder(
                transceiver: $this->transceiver,
                mailboxPath: new MailboxPath($name, $delimiter),
                specialUseAttr: $specialUse,
                attributes: $attrs,
            );
        }

        return $folders;
    }

    private function parseNamespaceResponse(mixed $data): NamespaceInfo
    {
        if (!is_string($data)) {
            return new NamespaceInfo();
        }

        // NAMESPACE response is complex: (("prefix" "delimiter")...) ((...)) ((...))
        // Simplified parsing
        $personal = [];
        $other = [];
        $shared = [];

        preg_match_all('/\("([^"]*)"\s+"([^"]*)"\)/', $data, $matches, PREG_SET_ORDER);

        $index = 0;
        foreach ($matches as $match) {
            $entry = ['prefix' => $match[1], 'delimiter' => $match[2]];
            if ($index === 0) {
                $personal[] = $entry;
            } elseif ($index === 1) {
                $other[] = $entry;
            } else {
                $shared[] = $entry;
            }
            $index++;
        }

        return new NamespaceInfo($personal, $other, $shared);
    }
}
