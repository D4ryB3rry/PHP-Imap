<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum Capability: string
{
    case Imap4rev1 = 'IMAP4rev1';
    case Imap4rev2 = 'IMAP4rev2';
    case Condstore = 'CONDSTORE';
    case Qresync = 'QRESYNC';
    case ObjectId = 'OBJECTID';
    case Move = 'MOVE';
    case StatusSize = 'STATUS=SIZE';
    case SaveDate = 'SAVEDATE';
    case Utf8Accept = 'UTF8=ACCEPT';
    case ListStatus = 'LIST-STATUS';
    case LiteralMinus = 'LITERAL-';
    case LiteralPlus = 'LITERAL+';
    case SpecialUse = 'SPECIAL-USE';
    case Sort = 'SORT';
    case Thread = 'THREAD';
    case Id = 'ID';
    case Idle = 'IDLE';
    case Namespace = 'NAMESPACE';
    case Enable = 'ENABLE';
    case Unselect = 'UNSELECT';
    case Compress = 'COMPRESS=DEFLATE';
    case SaslIr = 'SASL-IR';
    case AuthPlain = 'AUTH=PLAIN';
    case AuthLogin = 'AUTH=LOGIN';
    case AuthXOAuth2 = 'AUTH=XOAUTH2';
}
