<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * @group integration
 * @group provider-outlook
 */
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
}
