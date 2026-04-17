<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum StatusAttribute: string
{
    case Messages = 'MESSAGES';
    case Recent = 'RECENT';
    case UidNext = 'UIDNEXT';
    case UidValidity = 'UIDVALIDITY';
    case Unseen = 'UNSEEN';
    case HighestModSeq = 'HIGHESTMODSEQ';
    case Size = 'SIZE';
    case MailboxId = 'MAILBOXID';
}
