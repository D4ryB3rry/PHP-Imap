<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum ContentTransferEncoding: string
{
    case SevenBit = '7bit';
    case EightBit = '8bit';
    case Binary = 'binary';
    case QuotedPrintable = 'quoted-printable';
    case Base64 = 'base64';
}
