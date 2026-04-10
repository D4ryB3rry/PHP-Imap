<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Enum;

use D4ry\ImapClient\Enum\Capability;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Enum\Capability
 */
final class CapabilityTest extends TestCase
{
    public function testKnownCapabilitiesResolveFromString(): void
    {
        self::assertSame(Capability::Imap4rev1, Capability::from('IMAP4rev1'));
        self::assertSame(Capability::Idle, Capability::from('IDLE'));
        self::assertSame(Capability::SaslIr, Capability::from('SASL-IR'));
        self::assertSame(Capability::AuthXOAuth2, Capability::from('AUTH=XOAUTH2'));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        self::assertNull(Capability::tryFrom('NOPE'));
    }
}
