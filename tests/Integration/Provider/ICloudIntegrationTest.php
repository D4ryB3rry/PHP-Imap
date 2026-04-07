<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-icloud')]
final class ICloudIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'ICLOUD';
    }

    protected function defaultHost(): string
    {
        return 'imap.mail.me.com';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('iCloud end-to-end coverage pending — requires app-specific password.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('iCloud folder listing pending.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('iCloud latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('iCloud unread search pending.');
    }
}
