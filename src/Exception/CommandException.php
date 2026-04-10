<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Exception;

class CommandException extends ImapException
{
    public function __construct(
        public string $tag,
        public string $command,
        public string $responseText,
        public string $status,
    ) {
        parent::__construct(
            sprintf('IMAP command %s [%s] failed with %s: %s', $command, $tag, $status, $responseText)
        );
    }
}
