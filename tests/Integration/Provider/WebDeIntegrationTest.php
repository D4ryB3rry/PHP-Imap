<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('provider-webde')]
final class WebDeIntegrationTest extends AbstractProviderIntegrationTestCase
{
    protected function envPrefix(): string
    {
        return 'WEBDE';
    }

    protected function defaultHost(): string
    {
        return 'imap.web.de';
    }

    public function testCanConnect(): void
    {
        $this->connect();
        self::markTestIncomplete('Web.de end-to-end coverage pending.');
    }

    public function testListsFolders(): void
    {
        $this->connect();
        self::markTestIncomplete('Web.de folder listing pending — German folder names.');
    }

    public function testFetchesLatestMessage(): void
    {
        $this->connect();
        self::markTestIncomplete('Web.de latest-message fetch pending.');
    }

    public function testSearchUnread(): void
    {
        $this->connect();
        self::markTestIncomplete('Web.de unread search pending.');
    }
}
