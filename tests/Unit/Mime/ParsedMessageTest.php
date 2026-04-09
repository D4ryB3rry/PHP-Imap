<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Mime;

use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Mime\ParsedMessage;
use D4ry\ImapClient\Mime\ParsedPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedMessage::class)]
#[CoversClass(ParsedPart::class)]
final class ParsedMessageTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $message = new ParsedMessage(
            headers: ['Subject' => ['Hello'], 'X-Custom' => ['one', 'two']],
            textBody: 'body',
            htmlBody: null,
        );

        self::assertSame('Hello', $message->header('subject'));
        self::assertSame('one', $message->header('X-CUSTOM'));
        self::assertNull($message->header('Missing'));
    }

    public function testHeaderReturnsNullWhenValuesEmpty(): void
    {
        $message = new ParsedMessage(
            headers: ['Empty' => []],
            textBody: null,
            htmlBody: null,
        );

        self::assertNull($message->header('Empty'));
    }

    public function testHeaderAllReturnsAllValues(): void
    {
        $message = new ParsedMessage(
            headers: ['Received' => ['srv1', 'srv2']],
            textBody: null,
            htmlBody: null,
        );

        self::assertSame(['srv1', 'srv2'], $message->headerAll('received'));
        self::assertSame([], $message->headerAll('Missing'));
    }

    public function testHeaderAllLookupIsCaseInsensitiveForUppercaseInput(): void
    {
        // The strtolower() on the lookup name (line 38) is what makes the
        // case-insensitive comparison work. Removing it (UnwrapStrToLower
        // mutant) would compare an UPPERCASE input against the lowercased
        // header key and miss it.
        $message = new ParsedMessage(
            headers: ['Received' => ['srv1', 'srv2']],
            textBody: null,
            htmlBody: null,
        );

        self::assertSame(['srv1', 'srv2'], $message->headerAll('RECEIVED'));
    }

    public function testAttachmentsAndInlinePartsFiltering(): void
    {
        $attachment = new ParsedPart(
            mimeType: 'application/pdf',
            content: 'PDF',
            filename: 'doc.pdf',
            isInline: false,
            encoding: ContentTransferEncoding::Base64,
        );
        $inline = new ParsedPart(
            mimeType: 'image/png',
            content: 'PNG',
            filename: 'logo.png',
            isInline: true,
            contentId: 'logo@example.com',
        );
        $bodyPart = new ParsedPart(
            mimeType: 'application/octet-stream',
            content: 'X',
        );

        $message = new ParsedMessage(
            headers: [],
            textBody: null,
            htmlBody: null,
            parts: [$attachment, $inline, $bodyPart],
        );

        self::assertSame([$attachment], array_values($message->attachments()));
        self::assertSame([$inline], array_values($message->inlineParts()));
    }
}
