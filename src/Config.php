<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Auth\Contract\CredentialInterface;
use D4ry\ImapClient\Enum\Encryption;

readonly class Config
{
    public function __construct(
        public string $host,
        public CredentialInterface $credential,
        public int $port = 993,
        public Encryption $encryption = Encryption::Tls,
        public float $timeout = 30.0,
        public bool $enableCondstore = false,
        public bool $enableQresync = false,
        public bool $utf8Accept = false,
        public ?array $clientId = null,
    ) {
    }

    public static function create(
        string $host,
        CredentialInterface $credential,
    ): self {
        return new self(host: $host, credential: $credential);
    }
}
