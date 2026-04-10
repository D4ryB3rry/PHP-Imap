<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * @group integration
 * @group provider-gmx
 */
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
}
