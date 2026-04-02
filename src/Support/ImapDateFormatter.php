<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Support;

use D4ry\ImapClient\Exception\ParseException;

class ImapDateFormatter
{
    public static function toImapDate(\DateTimeInterface $date): string
    {
        return $date->format('j-M-Y');
    }

    public static function toImapDateTime(\DateTimeInterface $date): string
    {
        return $date->format('d-M-Y H:i:s O');
    }

    public static function parse(string $imapDate): \DateTimeImmutable
    {
        $formats = [
            'd-M-Y H:i:s O',
            'j-M-Y H:i:s O',
            'd-M-Y H:i:s',
            'j-M-Y H:i:s',
            'D, d M Y H:i:s O',
            'd M Y H:i:s O',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, trim($imapDate));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new ParseException(sprintf('Unable to parse IMAP date: %s', $imapDate));
    }
}
