<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration;

use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Mailbox;
use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Base class for provider-specific end-to-end IMAP tests.
 *
 * Each subclass declares the env-var prefix it expects (e.g. "GMAIL"),
 * and integration tests are automatically skipped if the corresponding
 * env vars are not set, so the suite never fails on developer machines
 * without live credentials.
 *
 * Required env vars per provider:
 *   IMAP_{PREFIX}_HOST       e.g. imap.gmail.com
 *   IMAP_{PREFIX}_PORT       e.g. 993
 *   IMAP_{PREFIX}_USER       login user
 *   IMAP_{PREFIX}_PASS       password / app password / token
 *   IMAP_{PREFIX}_ENCRYPTION tls|starttls|none (default tls)
 */
abstract class AbstractProviderIntegrationTestCase extends TestCase
{
    abstract protected function envPrefix(): string;

    /**
     * Default host suggestion shown to developers when env is missing.
     */
    abstract protected function defaultHost(): string;

    /**
     * Override in subclasses that need stream-context SSL options
     * (e.g. self-signed certificates from Proton Bridge).
     *
     * @return array<string, mixed>
     */
    protected function sslOptions(): array
    {
        return [];
    }

    /**
     * @return array{host:string,port:int,user:string,pass:string,encryption:Encryption}
     */
    final protected function loadConfig(): array
    {
        $prefix = 'IMAP_' . $this->envPrefix() . '_';

        $host = $this->env($prefix . 'HOST');
        $user = $this->env($prefix . 'USER');
        $pass = $this->env($prefix . 'PASS');

        if ($host === null || $user === null || $pass === null) {
            self::markTestSkipped(sprintf(
                'Skipping %s integration test — set %sHOST, %sUSER, %sPASS to run (e.g. %sHOST=%s).',
                $this->envPrefix(),
                $prefix,
                $prefix,
                $prefix,
                $prefix,
                $this->defaultHost(),
            ));
        }

        $port = (int)($this->env($prefix . 'PORT') ?? '993');
        $encryptionRaw = $this->env($prefix . 'ENCRYPTION') ?? 'tls';
        $encryption = Encryption::tryFrom($encryptionRaw) ?? Encryption::Tls;

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'pass' => $pass,
            'encryption' => $encryption,
        ];
    }

    /**
     * @param array<string, mixed>|null $sslOptions Defaults to {@see self::sslOptions()} when null.
     */
    final protected function connect(?array $sslOptions = null): Mailbox
    {
        $cfg = $this->loadConfig();
        $config = new Config(
            host: $cfg['host'],
            credential: new PlainCredential($cfg['user'], $cfg['pass']),
            port: $cfg['port'],
            encryption: $cfg['encryption'],
            sslOptions: $sslOptions ?? $this->sslOptions(),
        );

        return Mailbox::connect($config);
    }

    #[CoversNothing]
    final public function testCanConnect(): void
    {
        $mailbox = $this->connect();

        try {
            $capabilities = $mailbox->capabilities();
            self::assertNotEmpty(
                $capabilities,
                'Provider returned an empty CAPABILITY list — handshake or auth likely broken.',
            );
        } finally {
            $mailbox->disconnect();
        }
    }

    #[CoversNothing]
    final public function testListsFolders(): void
    {
        $mailbox = $this->connect();

        try {
            $folders = $mailbox->folders()->toArray();

            self::assertNotEmpty($folders, 'Provider returned no folders at all.');

            $hasInbox = false;
            foreach ($folders as $folder) {
                if (strcasecmp($folder->path()->path, 'INBOX') === 0) {
                    $hasInbox = true;
                    break;
                }
            }

            self::assertTrue($hasInbox, 'Provider folder list does not contain INBOX.');
        } finally {
            $mailbox->disconnect();
        }
    }

    #[CoversNothing]
    final public function testFetchesLatestMessage(): void
    {
        $mailbox = $this->connect();

        try {
            $inbox = $mailbox->inbox();
            $inbox->examine();

            if ($inbox->status()->messages === 0) {
                self::markTestSkipped('INBOX is empty — cannot fetch a latest message.');
            }

            $result = $inbox->search(new Search()->all());
            self::assertNotEmpty($result->uids, 'INBOX SEARCH ALL returned no UIDs despite non-zero MESSAGES count.');

            $latestUid = $result->uids[array_key_last($result->uids)];
            self::assertInstanceOf(Uid::class, $latestUid);
            self::assertGreaterThan(0, $latestUid->value);

            $message = $inbox->message($latestUid);

            self::assertSame($latestUid->value, $message->uid()->value);
            self::assertInstanceOf(Envelope::class, $message->envelope());
        } finally {
            $mailbox->disconnect();
        }
    }

    #[CoversNothing]
    final public function testSearchUnread(): void
    {
        $mailbox = $this->connect();

        try {
            $inbox = $mailbox->inbox();
            $inbox->examine();

            $result = $inbox->search(new Search()->unread());

            self::assertInstanceOf(SearchResult::class, $result);
            foreach ($result->uids as $uid) {
                self::assertInstanceOf(Uid::class, $uid);
                self::assertGreaterThan(0, $uid->value);
            }
        } finally {
            $mailbox->disconnect();
        }
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === '') ? null : (string)$value;
    }
}
