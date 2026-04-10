<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * @group integration
 * @group provider-gmail
 */
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
}
