<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Auth;

use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Protocol\Transceiver;

class LoginCredential extends Credential
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
    }

    public function mechanism(): string
    {
        return 'LOGIN';
    }

    public function authenticate(Transceiver $transceiver): void
    {
        $quotedUser = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->username) . '"';
        $quotedPass = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->password) . '"';

        try {
            $transceiver->command('LOGIN', $quotedUser, $quotedPass);
        } catch (\D4ry\ImapClient\Exception\CommandException $e) {
            throw new AuthenticationException('LOGIN authentication failed: ' . $e->responseText, 0, $e);
        }
    }
}
