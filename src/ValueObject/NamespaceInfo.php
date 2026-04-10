<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

class NamespaceInfo
{
    /**
     * @param array<array{prefix: string, delimiter: string}> $personal
     * @param array<array{prefix: string, delimiter: string}> $other
     * @param array<array{prefix: string, delimiter: string}> $shared
     */
    public function __construct(
        public array $personal = [],
        public array $other = [],
        public array $shared = [],
    ) {
    }
}
