<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum SpecialUse: string
{
    case All = '\All';
    case Archive = '\Archive';
    case Drafts = '\Drafts';
    case Flagged = '\Flagged';
    case Junk = '\Junk';
    case Sent = '\Sent';
    case Trash = '\Trash';
    case Inbox = 'INBOX';
}
