<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * @group integration
 * @group provider-fastmail
 */
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
}
