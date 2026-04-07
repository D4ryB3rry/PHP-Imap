<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-yahoo')]
final class YahooIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'YAHOO';
    }

    protected function defaultHost(): string
    {
        return 'imap.mail.yahoo.com';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('Yahoo end-to-end coverage pending — requires app password.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('Yahoo folder listing pending.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('Yahoo latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('Yahoo unread search pending.');
    }
}
