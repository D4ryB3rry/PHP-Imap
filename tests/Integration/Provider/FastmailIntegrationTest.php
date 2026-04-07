<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-fastmail')]
final class FastmailIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'FASTMAIL';
    }

    protected function defaultHost(): string
    {
        return 'imap.fastmail.com';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('Fastmail end-to-end coverage pending — strong CONDSTORE/QRESYNC support to verify.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('Fastmail folder listing pending.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('Fastmail latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('Fastmail unread search pending.');
    }
}
