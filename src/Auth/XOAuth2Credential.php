<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Auth;

use D4ry\ImapClient\Auth\Contract\TokenRefresherInterface;
use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Transceiver;

class XOAuth2Credential extends Credential
{
    public function __construct(
        public readonly string $email,
        public string $accessToken,
        public readonly ?TokenRefresherInterface $tokenRefresher = null,
    ) {
    }

    public function mechanism(): string
    {
        return 'XOAUTH2';
    }

    public function authenticate(Transceiver $transceiver): void
    {
        $token = $this->buildOAuth2String();

        if ($transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::SaslIr)) {
            try {
                $transceiver->command('AUTHENTICATE', 'XOAUTH2', $token);
                return;
            } catch (\D4ry\ImapClient\Exception\CommandException $e) {
                if ($this->tokenRefresher !== null) {
                    $this->accessToken = $this->tokenRefresher->refresh($this->accessToken);
                    $token = $this->buildOAuth2String();

                    try {
                        $transceiver->command('AUTHENTICATE', 'XOAUTH2', $token);
                        return;
                    } catch (\D4ry\ImapClient\Exception\CommandException $e2) {
                        throw new AuthenticationException('XOAUTH2 authentication failed after token refresh: ' . $e2->responseText, 0, $e2);
                    }
                }

                throw new AuthenticationException('XOAUTH2 authentication failed: ' . $e->responseText, 0, $e);
            }
        }

        $tag = $transceiver->getTagGenerator()->next();
        $transceiver->getConnection()->write($tag->value . " AUTHENTICATE XOAUTH2\r\n");
        $response = $transceiver->readResponseForTag($tag->value);

        if ($response->tag === '+') {
            $transceiver->sendContinuationData($token);
            $response = $transceiver->readResponseForTag($tag->value);
        }

        if ($response->status !== ResponseStatus::Ok) {
            throw new AuthenticationException('XOAUTH2 authentication failed: ' . $response->text);
        }
    }

    private function buildOAuth2String(): string
    {
        $authString = sprintf("user=%s\x01auth=Bearer %s\x01\x01", $this->email, $this->accessToken);

        return base64_encode($authString);
    }
}
