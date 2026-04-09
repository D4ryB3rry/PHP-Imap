<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Enum\Encryption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
#[UsesClass(PlainCredential::class)]
final class ConfigTest extends TestCase
{
    public function testCreateAppliesDefaults(): void
    {
        $credential = new PlainCredential('user', 'pass');
        $config = Config::create('imap.example.com', $credential);

        self::assertSame('imap.example.com', $config->host);
        self::assertSame($credential, $config->credential);
        self::assertSame(993, $config->port);
        self::assertSame(Encryption::Tls, $config->encryption);
        self::assertSame(30.0, $config->timeout);
        self::assertFalse($config->enableCondstore);
        self::assertFalse($config->enableQresync);
        self::assertFalse($config->utf8Accept);
        self::assertNull($config->clientId);
        // Kills the TrueValue mutant on the recordRedactCredentials default
        // — recording must redact credentials by default for safety.
        self::assertTrue($config->recordRedactCredentials);
    }

    public function testFullConstructor(): void
    {
        $credential = new PlainCredential('user', 'pass');
        $config = new Config(
            host: 'imap.example.com',
            credential: $credential,
            port: 143,
            encryption: Encryption::StartTls,
            timeout: 10.0,
            enableCondstore: true,
            enableQresync: true,
            utf8Accept: true,
            clientId: ['name' => 'test'],
        );

        self::assertSame(143, $config->port);
        self::assertSame(Encryption::StartTls, $config->encryption);
        self::assertSame(10.0, $config->timeout);
        self::assertTrue($config->enableCondstore);
        self::assertTrue($config->enableQresync);
        self::assertTrue($config->utf8Accept);
        self::assertSame(['name' => 'test'], $config->clientId);
    }
}
