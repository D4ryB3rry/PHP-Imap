<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-gmx')]
final class GmxIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'GMX';
    }

    protected function defaultHost(): string
    {
        return 'imap.gmx.net';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('GMX end-to-end coverage pending.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('GMX folder listing pending — uses German folder names like "Entwürfe" (UTF-7).');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('GMX latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('GMX unread search pending.');
    }
}
