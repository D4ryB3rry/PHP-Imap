<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Mime;

use D4ry\ImapClient\Mime\HeaderDecoder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Mime\HeaderDecoder
 */
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
        self::assertSame('hello', HeaderDecoder::convertToUtf8('hello', 'ascii'));
    }

    public function testConvertToUtf8FromIso88591(): void
    {
        $latin1 = mb_convert_encoding('Grüße', 'ISO-8859-1', 'UTF-8');
        self::assertIsString($latin1);

        self::assertSame('Grüße', HeaderDecoder::convertToUtf8($latin1, 'ISO-8859-1'));
    }

    public function testDecodeReturnsOriginalOnInvalidBase64(): void
    {
        // '@@@@' is not valid base64 in strict mode → decode falls back to raw matches[0].
        $encoded = '=?UTF-8?B?@@@@?=';

        self::assertSame($encoded, HeaderDecoder::decode($encoded));
    }

    public function testParseHeadersIgnoresEmptyAndMalformedLines(): void
    {
        $block = "Subject: Hi\r\n\r\nNoColonLine\r\nFrom: bob@example.com";

        $headers = HeaderDecoder::parseHeaders($block);

        self::assertSame(['Hi'], $headers['Subject']);
        self::assertSame(['bob@example.com'], $headers['From']);
        self::assertArrayNotHasKey('NoColonLine', $headers);
    }

    public function testParseContentTypeSkipsParamsWithoutEquals(): void
    {
        $result = HeaderDecoder::parseContentType('text/plain; charset=utf-8; broken');

        self::assertSame('text/plain', $result['type']);
        self::assertSame('utf-8', $result['params']['charset']);
        self::assertArrayNotHasKey('broken', $result['params']);
    }

    public function testParseContentTypeRfc2231Parameter(): void
    {
        $result = HeaderDecoder::parseContentType("application/octet-stream; name*=UTF-8''Gr%C3%BC%C3%9Fe.bin");

        self::assertSame('application/octet-stream', $result['type']);
        self::assertSame('Grüße.bin', $result['params']['name']);
    }

    public function testParseContentDispositionSkipsParamsWithoutEquals(): void
    {
        $result = HeaderDecoder::parseContentDisposition('attachment; broken; filename="x.txt"');

        self::assertSame('attachment', $result['disposition']);
        self::assertSame('x.txt', $result['params']['filename']);
        self::assertArrayNotHasKey('broken', $result['params']);
    }

    public function testRfc2231FallbackWithoutCharsetPrefix(): void
    {
        // Continuation parameter without the charset'lang' prefix → falls through to rawurldecode.
        $result = HeaderDecoder::parseContentDisposition('attachment; filename*=plain%20name.txt');

        self::assertSame('plain name.txt', $result['params']['filename']);
    }
}
