<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\ValueObject;

use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\ValueObject\BodyStructure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BodyStructure::class)]
final class BodyStructureTest extends TestCase
{
    public function testMimeTypeIsLowercased(): void
    {
        $bs = new BodyStructure('TEXT', 'PLAIN');

        self::assertSame('text/plain', $bs->mimeType());
    }

    public function testIsMultipart(): void
    {
        self::assertTrue((new BodyStructure('MULTIPART', 'MIXED'))->isMultipart());
        self::assertFalse((new BodyStructure('TEXT', 'PLAIN'))->isMultipart());
    }

    public function testFilenameFromDisposition(): void
    {
        $bs = new BodyStructure(
            type: 'APPLICATION',
            subtype: 'PDF',
            dispositionFilename: 'doc.pdf',
        );

        self::assertSame('doc.pdf', $bs->filename());
        self::assertTrue($bs->isAttachment());
    }

    public function testFilenameFromParameterName(): void
    {
        $bs = new BodyStructure(
            type: 'IMAGE',
            subtype: 'PNG',
            parameters: ['name' => 'pic.png'],
        );

        self::assertSame('pic.png', $bs->filename());
        self::assertTrue($bs->isAttachment());
    }

    public function testInlineWithContentId(): void
    {
        $bs = new BodyStructure(
            type: 'IMAGE',
            subtype: 'PNG',
            id: '<cid@x>',
            disposition: 'inline',
        );

        self::assertTrue($bs->isInline());
    }

    public function testCharsetFromParameters(): void
    {
        $bs = new BodyStructure('TEXT', 'PLAIN', parameters: ['charset' => 'utf-8']);

        self::assertSame('utf-8', $bs->charset());
    }

    public function testEncodingProperty(): void
    {
        $bs = new BodyStructure('TEXT', 'PLAIN', encoding: ContentTransferEncoding::Base64);

        self::assertSame(ContentTransferEncoding::Base64, $bs->encoding);
    }
}
