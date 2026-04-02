<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Exception;

class ReadOnlyCollectionException extends \LogicException
{
    public function __construct()
    {
        parent::__construct('This collection is read-only.');
    }
}
