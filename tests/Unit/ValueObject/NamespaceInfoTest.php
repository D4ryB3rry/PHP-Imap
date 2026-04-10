<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\ValueObject\NamespaceInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\ValueObject\NamespaceInfo
 */
final class NamespaceInfoTest extends TestCase
{
    public function testDefaultsToEmptyArrays(): void
    {
        $info = new NamespaceInfo();

        self::assertSame([], $info->personal);
        self::assertSame([], $info->other);
        self::assertSame([], $info->shared);
    }

    public function testStoresProvidedNamespaces(): void
    {
        $personal = [['prefix' => '', 'delimiter' => '/']];
        $other = [['prefix' => 'Other Users/', 'delimiter' => '/']];
        $shared = [
            ['prefix' => 'Public Folders/', 'delimiter' => '/'],
            ['prefix' => 'Shared/', 'delimiter' => '.'],
        ];

        $info = new NamespaceInfo($personal, $other, $shared);

        self::assertSame($personal, $info->personal);
        self::assertSame($other, $info->other);
        self::assertSame($shared, $info->shared);
    }

    public function testSupportsNamedArguments(): void
    {
        $shared = [['prefix' => 'Public/', 'delimiter' => '/']];

        $info = new NamespaceInfo(shared: $shared);

        self::assertSame([], $info->personal);
        self::assertSame([], $info->other);
        self::assertSame($shared, $info->shared);
    }

}
