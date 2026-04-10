<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol;

use D4ry\ImapClient\Protocol\StreamingFetchState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Protocol\StreamingFetchState
 */
final class StreamingFetchStateTest extends TestCase
{
    public function testConstructorStoresTagAndInitializesEmptyState(): void
    {
        $state = new StreamingFetchState('A0042');

        self::assertSame('A0042', $state->tag);
        self::assertSame([], $state->fetchQueue);
        self::assertSame([], $state->otherUntagged);
        self::assertNull($state->finalResponse);
        self::assertFalse($state->completed);
    }
}
