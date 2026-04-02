<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum FetchAttribute: string
{
    case Envelope = 'ENVELOPE';
    case Body = 'BODY';
    case BodyStructure = 'BODYSTRUCTURE';
    case Flags = 'FLAGS';
    case InternalDate = 'INTERNALDATE';
    case Rfc822Size = 'RFC822.SIZE';
    case Uid = 'UID';
    case ModSeq = 'MODSEQ';
    case EmailId = 'EMAILID';
    case ThreadId = 'THREADID';
    case SaveDate = 'SAVEDATE';
}
