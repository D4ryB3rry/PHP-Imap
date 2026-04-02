<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Exception;

class CommandException extends ImapException
{
    public function __construct(
        public readonly string $tag,
        public readonly string $command,
        public readonly string $responseText,
        public readonly string $status,
    ) {
        parent::__construct(
            sprintf('IMAP command %s [%s] failed with %s: %s', $command, $tag, $status, $responseText)
        );
    }
}
