<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class FetchAttribute
{
    public const Envelope = 'ENVELOPE';
    public const Body = 'BODY';
    public const BodyStructure = 'BODYSTRUCTURE';
    public const Flags = 'FLAGS';
    public const InternalDate = 'INTERNALDATE';
    public const Rfc822Size = 'RFC822.SIZE';
    public const Uid = 'UID';
    public const ModSeq = 'MODSEQ';
    public const EmailId = 'EMAILID';
    public const ThreadId = 'THREADID';
    public const SaveDate = 'SAVEDATE';

    private const MAP = [
        'ENVELOPE' => self::Envelope,
        'BODY' => self::Body,
        'BODYSTRUCTURE' => self::BodyStructure,
        'FLAGS' => self::Flags,
        'INTERNALDATE' => self::InternalDate,
        'RFC822.SIZE' => self::Rfc822Size,
        'UID' => self::Uid,
        'MODSEQ' => self::ModSeq,
        'EMAILID' => self::EmailId,
        'THREADID' => self::ThreadId,
        'SAVEDATE' => self::SaveDate,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"FetchAttribute\"");
    }

    public static function tryFrom(string $value): ?string
    {
        return self::MAP[$value] ?? null;
    }

    /** @return string[] */
    public static function cases(): array
    {
        return array_values(self::MAP);
    }
}
