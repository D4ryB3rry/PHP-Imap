<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Integration\Provider;

use D4ry\ImapClient\Tests\Integration\AbstractProviderIntegrationTestCase;

/**
 * @group integration
 * @group provider-webde
 */
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
}
