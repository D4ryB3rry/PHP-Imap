<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Auth;

use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Transceiver;

class PlainCredential extends Credential
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
    }

    public function mechanism(): string
    {
        return 'PLAIN';
    }

    public function authenticate(Transceiver $transceiver): void
    {
        $payload = base64_encode("\x00" . $this->username . "\x00" . $this->password);

        if ($transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::SaslIr)) {
            try {
                $transceiver->command('AUTHENTICATE', 'PLAIN', $payload);
            } catch (\D4ry\ImapClient\Exception\CommandException $e) {
                throw new AuthenticationException('PLAIN authentication failed: ' . $e->responseText, 0, $e);
            }

            return;
        }

        $tag = $transceiver->getTagGenerator()->next();
        $transceiver->getConnection()->write($tag->value . " AUTHENTICATE PLAIN\r\n");
        $response = $transceiver->readResponseForTag($tag->value);

        if ($response->tag === '+') {
            $transceiver->sendContinuationData($payload);
            $response = $transceiver->readResponseForTag($tag->value);
        }

        if ($response->status !== ResponseStatus::Ok) {
            throw new AuthenticationException('PLAIN authentication failed: ' . $response->text);
        }
    }
}
