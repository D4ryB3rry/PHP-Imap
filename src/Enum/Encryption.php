<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum Encryption: string
{
    case Tls = 'tls';
    case StartTls = 'starttls';
    case None = 'none';
}
