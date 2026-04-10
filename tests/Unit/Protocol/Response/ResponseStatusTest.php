<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Protocol\Response\ResponseStatus
 */
final class ResponseStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'OK'      => ['OK', ResponseStatus::Ok];
        yield 'NO'      => ['NO', ResponseStatus::No];
        yield 'BAD'     => ['BAD', ResponseStatus::Bad];
        yield 'PREAUTH' => ['PREAUTH', ResponseStatus::PreAuth];
        yield 'BYE'     => ['BYE', ResponseStatus::Bye];
    }

    /**
     * @dataProvider statusProvider
     */
    public function testFromString(string $value, string $expected): void
    {
        self::assertSame($expected, ResponseStatus::from($value));
        self::assertSame($value, $expected);
    }

    public function testCasesContainsAllStatuses(): void
    {
        $cases = ResponseStatus::cases();

        self::assertCount(5, $cases);
        self::assertContains(ResponseStatus::Ok, $cases);
        self::assertContains(ResponseStatus::No, $cases);
        self::assertContains(ResponseStatus::Bad, $cases);
        self::assertContains(ResponseStatus::PreAuth, $cases);
        self::assertContains(ResponseStatus::Bye, $cases);
    }
}
