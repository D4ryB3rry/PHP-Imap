<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration;

use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Mailbox;
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

        $port = (int) ($this->env($prefix . 'PORT') ?? '993');
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

    final protected function connect(): Mailbox
    {
        $cfg = $this->loadConfig();
        $config = new Config(
            host: $cfg['host'],
            credential: new PlainCredential($cfg['user'], $cfg['pass']),
            port: $cfg['port'],
            encryption: $cfg['encryption'],
        );

        return Mailbox::connect($config);
    }

    private function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === '') ? null : (string) $value;
    }
}
