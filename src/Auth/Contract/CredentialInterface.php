<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Auth\Contract;

use D4ry\ImapClient\Protocol\Transceiver;

interface CredentialInterface
{
    public function mechanism(): string;

    public function authenticate(Transceiver $transceiver): void;
}
