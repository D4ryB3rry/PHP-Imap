<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-gmail')]
final class GmailIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'GMAIL';
    }

    protected function defaultHost(): string
    {
        return 'imap.gmail.com';
    }

    public function testCanConnect(): void
    {
        $mailbox = $this->connect();

        self::markTestIncomplete('Gmail end-to-end coverage pending — assert capability set, INBOX selectable, OAuth2 path.');
    }

    public function testListsFolders(): void
    {
        $this->connect();

        self::markTestIncomplete('Gmail folder listing test pending — Gmail uses [Gmail]/* labels and "All Mail".');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();

        self::markTestIncomplete('Gmail latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();

        self::markTestIncomplete('Gmail unread search pending.');
    }
}
