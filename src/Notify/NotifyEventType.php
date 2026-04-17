<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 event group tokens — the keywords that appear inside the
 * `(events)` parenthesised list of a NOTIFY SET command.
 */
enum NotifyEventType: string
{
    case MessageNew = 'MessageNew';
    case MessageExpunge = 'MessageExpunge';
    case FlagChange = 'FlagChange';
    case AnnotationChange = 'AnnotationChange';
    case MailboxName = 'MailboxName';
    case SubscriptionChange = 'SubscriptionChange';
    case MailboxMetadataChange = 'MailboxMetadataChange';
    case ServerMetadataChange = 'ServerMetadataChange';
}
