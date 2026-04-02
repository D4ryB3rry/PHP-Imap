<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum Flag: string
{
    case Seen = '\Seen';
    case Answered = '\Answered';
    case Flagged = '\Flagged';
    case Deleted = '\Deleted';
    case Draft = '\Draft';
    case Recent = '\Recent';

    public function imapString(): string
    {
        return $this->value;
    }
}
