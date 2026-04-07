<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Mime;

use D4ry\ImapClient\Mime\HeaderDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderDecoder::class)]
final class HeaderDecoderTest extends TestCase
{
    public function testDecodesBase64EncodedWord(): void
    {
        $encoded = '=?UTF-8?B?SGVsbG8gV29ybGQ=?=';

        self::assertSame('Hello World', HeaderDecoder::decode($encoded));
    }

    public function testDecodesQuotedPrintableEncodedWord(): void
    {
        $encoded = '=?UTF-8?Q?Hello_World?=';

        self::assertSame('Hello World', HeaderDecoder::decode($encoded));
    }

    public function testDecodesUmlautsViaBase64(): void
    {
        $encoded = '=?UTF-8?B?' . base64_encode('Grüße') . '?=';

        self::assertSame('Grüße', HeaderDecoder::decode($encoded));
    }

    public function testPlainAsciiPassesThrough(): void
    {
        self::assertSame('plain text', HeaderDecoder::decode('plain text'));
    }

    public function testParseHeadersUnfoldsContinuationLines(): void
    {
        $block = "Subject: Hello\r\n World\r\nFrom: alice@example.com";
        $headers = HeaderDecoder::parseHeaders($block);

        self::assertSame(['Hello World'], $headers['Subject']);
        self::assertSame(['alice@example.com'], $headers['From']);
    }

    public function testParseContentType(): void
    {
        $result = HeaderDecoder::parseContentType('text/plain; charset="utf-8"; format=flowed');

        self::assertSame('text/plain', $result['type']);
        self::assertSame('utf-8', $result['params']['charset']);
        self::assertSame('flowed', $result['params']['format']);
    }

    public function testParseContentDisposition(): void
    {
        $result = HeaderDecoder::parseContentDisposition('attachment; filename="report.pdf"');

        self::assertSame('attachment', $result['disposition']);
        self::assertSame('report.pdf', $result['params']['filename']);
    }

    public function testParseContentTypeRfc2231Filename(): void
    {
        $result = HeaderDecoder::parseContentDisposition("attachment; filename*=UTF-8''Gr%C3%BC%C3%9Fe.pdf");

        self::assertSame('attachment', $result['disposition']);
        self::assertSame('Grüße.pdf', $result['params']['filename']);
    }

    public function testConvertToUtf8AsciiPassthrough(): void
    {
        self::assertSame('hello', HeaderDecoder::convertToUtf8('hello', 'us-ascii'));
        self::assertSame('hello', HeaderDecoder::convertToUtf8('hello', 'UTF-8'));
    }
}
