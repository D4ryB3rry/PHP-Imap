<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

enum SortCriterion: string
{
    case Date = 'DATE';
    case Arrival = 'ARRIVAL';
    case From = 'FROM';
    case Subject = 'SUBJECT';
    case Size = 'SIZE';
    case Cc = 'CC';
    case To = 'TO';
}
