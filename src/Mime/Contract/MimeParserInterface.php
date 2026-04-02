<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Mime\Contract;

use D4ry\ImapClient\Mime\ParsedMessage;

interface MimeParserInterface
{
    public function parse(string $rawMessage): ParsedMessage;
}
