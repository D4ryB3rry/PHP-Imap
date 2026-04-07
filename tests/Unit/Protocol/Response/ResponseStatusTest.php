<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseStatus::class)]
final class ResponseStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ResponseStatus}>
     */
    public static function statusProvider(): iterable
    {
        yield 'OK'      => ['OK', ResponseStatus::Ok];
        yield 'NO'      => ['NO', ResponseStatus::No];
        yield 'BAD'     => ['BAD', ResponseStatus::Bad];
        yield 'PREAUTH' => ['PREAUTH', ResponseStatus::PreAuth];
        yield 'BYE'     => ['BYE', ResponseStatus::Bye];
    }

    #[DataProvider('statusProvider')]
    public function testFromString(string $value, ResponseStatus $expected): void
    {
        self::assertSame($expected, ResponseStatus::from($value));
        self::assertSame($value, $expected->value);
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
