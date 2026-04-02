<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Auth\Contract;

interface TokenRefresherInterface
{
    public function refresh(string $currentToken): string;
}
