<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Search;

use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\ValueObject\Uid;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Search\SearchResult
 * @uses \D4ry\ImapClient\ValueObject\Uid
 */
final class SearchResultTest extends TestCase
{
    public function testEmptyResult(): void
    {
        $result = new SearchResult([]);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->count());
        self::assertSame([], $result->uids);
        self::assertSame([], $result->uidValues());
        self::assertNull($result->highestModSeq);
    }

    public function testResultWithUids(): void
    {
        $uids = [new Uid(1), new Uid(7), new Uid(42)];
        $result = new SearchResult($uids);

        self::assertFalse($result->isEmpty());
        self::assertSame(3, $result->count());
        self::assertSame($uids, $result->uids);
        self::assertSame([1, 7, 42], $result->uidValues());
        self::assertNull($result->highestModSeq);
    }

    public function testResultWithHighestModSeq(): void
    {
        $result = new SearchResult([new Uid(5)], 987654);

        self::assertSame(987654, $result->highestModSeq);
        self::assertSame(1, $result->count());
        self::assertFalse($result->isEmpty());
        self::assertSame([5], $result->uidValues());
    }

    public function testHighestModSeqCanBeZero(): void
    {
        $result = new SearchResult([], 0);

        self::assertSame(0, $result->highestModSeq);
        self::assertTrue($result->isEmpty());
    }

    public function testUidValuesPreservesOrder(): void
    {
        $result = new SearchResult([new Uid(10), new Uid(2), new Uid(99), new Uid(3)]);

        self::assertSame([10, 2, 99, 3], $result->uidValues());
    }

}
