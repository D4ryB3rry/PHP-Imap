<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\UntaggedResponse
 */
final class ResponseTest extends TestCase
{
    public function testDefaults(): void
    {
        $response = new Response(ResponseStatus::Ok, 'A0001', 'Completed');

        self::assertSame(ResponseStatus::Ok, $response->status);
        self::assertSame('A0001', $response->tag);
        self::assertSame('Completed', $response->text);
        self::assertSame([], $response->untagged);
        self::assertNull($response->responseCode);
        self::assertTrue($response->isOk());
    }

    public function testIsOkReturnsFalseForOtherStatuses(): void
    {
        $no  = new Response(ResponseStatus::No, 'A0001', 'failed');
        $bad = new Response(ResponseStatus::Bad, 'A0001', 'parse error');

        self::assertFalse($no->isOk());
        self::assertFalse($bad->isOk());
    }

    public function testIsOkReturnsFalseForPreAuthAndBye(): void
    {
        $preAuth = new Response(ResponseStatus::PreAuth, 'A0001', 'authenticated');
        $bye     = new Response(ResponseStatus::Bye, 'A0001', 'closing');

        self::assertFalse($preAuth->isOk());
        self::assertFalse($bye->isOk());
    }

    public function testGetUntaggedByTypeIsCaseInsensitive(): void
    {
        $a = new UntaggedResponse('FETCH', ['seq' => 1]);
        $b = new UntaggedResponse('EXISTS', 5);
        $c = new UntaggedResponse('FETCH', ['seq' => 2]);

        $response = new Response(
            status: ResponseStatus::Ok,
            tag: 'A0001',
            text: 'OK',
            untagged: [$a, $b, $c],
        );

        $found = $response->getUntaggedByType('fetch');

        self::assertCount(2, $found);
        self::assertContains($a, $found);
        self::assertContains($c, $found);
    }

    public function testGetUntaggedByTypeReturnsEmptyArrayWhenMissing(): void
    {
        $response = new Response(ResponseStatus::Ok, 'A0001', 'OK');

        self::assertSame([], $response->getUntaggedByType('FETCH'));
    }

    public function testGetFirstUntaggedByTypeReturnsFirstMatch(): void
    {
        $first  = new UntaggedResponse('FETCH', ['seq' => 1]);
        $second = new UntaggedResponse('FETCH', ['seq' => 2]);

        $response = new Response(
            status: ResponseStatus::Ok,
            tag: 'A0001',
            text: 'OK',
            untagged: [$first, $second],
        );

        self::assertSame($first, $response->getFirstUntaggedByType('FETCH'));
    }

    public function testGetFirstUntaggedByTypeReturnsNullWhenMissing(): void
    {
        $response = new Response(ResponseStatus::Ok, 'A0001', 'OK');

        self::assertNull($response->getFirstUntaggedByType('FETCH'));
    }

    public function testResponseCodeIsExposed(): void
    {
        $response = new Response(
            status: ResponseStatus::Ok,
            tag: 'A0001',
            text: 'Completed',
            responseCode: 'READ-WRITE',
        );

        self::assertSame('READ-WRITE', $response->responseCode);
    }
}
