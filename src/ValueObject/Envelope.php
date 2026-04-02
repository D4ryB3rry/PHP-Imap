<?php

declare(strict_types=1);

namespace D4ry\ImapClient\ValueObject;

readonly class Envelope
{
    /**
     * @param Address[] $from
     * @param Address[] $sender
     * @param Address[] $replyTo
     * @param Address[] $to
     * @param Address[] $cc
     * @param Address[] $bcc
     */
    public function __construct(
        public ?\DateTimeImmutable $date,
        public ?string $subject,
        public array $from,
        public array $sender,
        public array $replyTo,
        public array $to,
        public array $cc,
        public array $bcc,
        public ?string $inReplyTo,
        public ?string $messageId,
    ) {
    }
}
