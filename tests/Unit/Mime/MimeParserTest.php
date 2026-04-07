<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Mime;

use D4ry\ImapClient\Mime\HeaderDecoder;
use D4ry\ImapClient\Mime\MimeParser;
use D4ry\ImapClient\Mime\ParsedMessage;
use D4ry\ImapClient\Mime\ParsedPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MimeParser::class)]
#[CoversClass(ParsedMessage::class)]
#[CoversClass(ParsedPart::class)]
#[UsesClass(HeaderDecoder::class)]
final class MimeParserTest extends TestCase
{
    public function testParsesSimpleTextMessage(): void
    {
        $raw = "Subject: Hi\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHello World";

        $parsed = (new MimeParser())->parse($raw);

        self::assertSame('Hello World', $parsed->textBody);
        self::assertNull($parsed->htmlBody);
        self::assertSame('Hi', $parsed->headers['Subject'][0] ?? null);
    }

    public function testParsesHtmlOnlyMessage(): void
    {
        $raw = "Content-Type: text/html; charset=UTF-8\r\n\r\n<p>Hi</p>";

        $parsed = (new MimeParser())->parse($raw);

        self::assertSame('<p>Hi</p>', $parsed->htmlBody);
        self::assertNull($parsed->textBody);
    }

    public function testParsesMultipartAlternative(): void
    {
        $raw = "Content-Type: multipart/alternative; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "plain version\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "\r\n"
            . "<p>html version</p>\r\n"
            . "--BOUND--\r\n";

        $parsed = (new MimeParser())->parse($raw);

        self::assertSame('plain version', $parsed->textBody);
        self::assertSame('<p>html version</p>', $parsed->htmlBody);
        self::assertSame([], $parsed->parts);
    }

    public function testParsesAttachmentInMultipartMixed(): void
    {
        $payload = base64_encode('PDFDATA');
        $raw = "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "see attached\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/pdf; name=\"doc.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"doc.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . $payload . "\r\n"
            . "--BOUND--\r\n";

        $parsed = (new MimeParser())->parse($raw);

        self::assertSame('see attached', $parsed->textBody);
        self::assertCount(1, $parsed->parts);
        self::assertSame('application/pdf', $parsed->parts[0]->mimeType);
        self::assertSame('doc.pdf', $parsed->parts[0]->filename);
        self::assertSame('PDFDATA', $parsed->parts[0]->content);
    }

    public function testQuotedPrintableDecoding(): void
    {
        $raw = "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "\r\n"
            . "Gr=C3=BC=C3=9Fe";

        $parsed = (new MimeParser())->parse($raw);

        self::assertSame('Grüße', $parsed->textBody);
    }
}
