<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * Proton Mail is only reachable via the local Proton Bridge IMAP gateway
 * (default 127.0.0.1:1143, STARTTLS, bridge-generated password).
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-proton')]
final class ProtonBridgeIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'PROTON';
    }

    protected function defaultHost(): string
    {
        return '127.0.0.1';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('Proton Bridge end-to-end coverage pending — uses local STARTTLS gateway on 1143.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('Proton folder listing pending.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('Proton latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('Proton unread search pending.');
    }
}
