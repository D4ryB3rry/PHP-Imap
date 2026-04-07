<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-outlook')]
final class OutlookIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'OUTLOOK';
    }

    protected function defaultHost(): string
    {
        return 'outlook.office365.com';
    }

    public function testCanConnect(): void
    {
        $this->connect();

        self::markTestIncomplete('Outlook/Office365 end-to-end coverage pending — verify XOAUTH2 + Modern Auth.');
    }

    public function testListsFolders(): void
    {
        $this->connect();

        self::markTestIncomplete('Outlook folder listing pending — uses localized folder names.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();

        self::markTestIncomplete('Outlook latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();

        self::markTestIncomplete('Outlook unread search pending.');
    }
}
