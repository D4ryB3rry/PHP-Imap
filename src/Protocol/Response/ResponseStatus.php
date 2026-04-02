<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol\Response;

enum ResponseStatus: string
{
    case Ok = 'OK';
    case No = 'NO';
    case Bad = 'BAD';
    case PreAuth = 'PREAUTH';
    case Bye = 'BYE';
}
