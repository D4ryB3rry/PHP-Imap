<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * Proton Mail is only reachable via the local Proton Bridge IMAP gateway
 * (default 127.0.0.1:1143, STARTTLS, bridge-generated password). The bridge
 * presents a self-signed certificate, so peer verification must be disabled.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-proton')]
final class ProtonBridgeIntegrationTest extends AbstractProviderIntegrationTestCase
{
    private const array SSL_OPTIONS = [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ];

    protected function envPrefix(): string
    {
        return 'PROTON';
    }

    protected function defaultHost(): string
    {
        return '127.0.0.1';
    }

    protected function sslOptions(): array
    {
        return self::SSL_OPTIONS;
    }
}
