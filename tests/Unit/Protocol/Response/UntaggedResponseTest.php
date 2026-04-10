<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Protocol\Response\UntaggedResponse
 */
final class UntaggedResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new UntaggedResponse('CAPABILITY');

        self::assertSame('CAPABILITY', $response->type);
        self::assertNull($response->data);
        self::assertNull($response->raw);
    }

    public function testWithDataAndRaw(): void
    {
        $data = ['IMAP4rev1', 'IDLE'];
        $response = new UntaggedResponse('CAPABILITY', $data, '* CAPABILITY IMAP4rev1 IDLE');

        self::assertSame('CAPABILITY', $response->type);
        self::assertSame($data, $response->data);
        self::assertSame('* CAPABILITY IMAP4rev1 IDLE', $response->raw);
    }

    public function testAcceptsArbitraryDataTypes(): void
    {
        $string = new UntaggedResponse('SEARCH', '1 2 3');
        $int    = new UntaggedResponse('EXISTS', 42);

        self::assertSame('1 2 3', $string->data);
        self::assertSame(42, $int->data);
    }
}
