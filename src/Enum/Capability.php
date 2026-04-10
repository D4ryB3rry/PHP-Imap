<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Enum;

final class Capability
{
    public const Imap4rev1 = 'IMAP4rev1';
    public const Imap4rev2 = 'IMAP4rev2';
    public const Condstore = 'CONDSTORE';
    public const Qresync = 'QRESYNC';
    public const ObjectId = 'OBJECTID';
    public const Move = 'MOVE';
    public const StatusSize = 'STATUS=SIZE';
    public const SaveDate = 'SAVEDATE';
    public const Utf8Accept = 'UTF8=ACCEPT';
    public const ListStatus = 'LIST-STATUS';
    public const LiteralMinus = 'LITERAL-';
    public const LiteralPlus = 'LITERAL+';
    public const SpecialUse = 'SPECIAL-USE';
    public const Sort = 'SORT';
    public const Thread = 'THREAD';
    public const Id = 'ID';
    public const Idle = 'IDLE';
    public const Namespace = 'NAMESPACE';
    public const Enable = 'ENABLE';
    public const Unselect = 'UNSELECT';
    public const Compress = 'COMPRESS=DEFLATE';
    public const SaslIr = 'SASL-IR';
    public const AuthPlain = 'AUTH=PLAIN';
    public const AuthLogin = 'AUTH=LOGIN';
    public const AuthXOAuth2 = 'AUTH=XOAUTH2';

    private const MAP = [
        'IMAP4rev1' => self::Imap4rev1,
        'IMAP4rev2' => self::Imap4rev2,
        'CONDSTORE' => self::Condstore,
        'QRESYNC' => self::Qresync,
        'OBJECTID' => self::ObjectId,
        'MOVE' => self::Move,
        'STATUS=SIZE' => self::StatusSize,
        'SAVEDATE' => self::SaveDate,
        'UTF8=ACCEPT' => self::Utf8Accept,
        'LIST-STATUS' => self::ListStatus,
        'LITERAL-' => self::LiteralMinus,
        'LITERAL+' => self::LiteralPlus,
        'SPECIAL-USE' => self::SpecialUse,
        'SORT' => self::Sort,
        'THREAD' => self::Thread,
        'ID' => self::Id,
        'IDLE' => self::Idle,
        'NAMESPACE' => self::Namespace,
        'ENABLE' => self::Enable,
        'UNSELECT' => self::Unselect,
        'COMPRESS=DEFLATE' => self::Compress,
        'SASL-IR' => self::SaslIr,
        'AUTH=PLAIN' => self::AuthPlain,
        'AUTH=LOGIN' => self::AuthLogin,
        'AUTH=XOAUTH2' => self::AuthXOAuth2,
    ];

    public static function from(string $value): string
    {
        return self::MAP[$value] ?? throw new \ValueError("\"$value\" is not a valid backing value for enum \"Capability\"");
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
