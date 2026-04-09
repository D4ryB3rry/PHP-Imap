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

    public function testFilenameDispositionFilenameTakesPrecedenceOverParameterName(): void
    {
        // Both fields populated → dispositionFilename must win. Kills the
        // Coalesce mutant on BodyStructure::filename() that swaps the
        // operand order of the `??` chain.
        $bs = new BodyStructure(
            type: 'IMAGE',
            subtype: 'PNG',
            parameters: ['name' => 'inline-name.png'],
            dispositionFilename: 'attachment-name.png',
        );

        self::assertSame('attachment-name.png', $bs->filename());
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

    public function testIsAttachmentWithExplicitDisposition(): void
    {
        $bs = new BodyStructure(
            type: 'APPLICATION',
            subtype: 'OCTET-STREAM',
            disposition: 'ATTACHMENT',
        );

        self::assertTrue($bs->isAttachment());
    }

    public function testInlineWithNameIsNotAttachment(): void
    {
        $bs = new BodyStructure(
            type: 'IMAGE',
            subtype: 'PNG',
            parameters: ['name' => 'pic.png'],
            disposition: 'inline',
        );

        self::assertFalse($bs->isAttachment());
        self::assertSame('pic.png', $bs->filename());
    }

    public function testInlineWithoutContentIdIsNotInline(): void
    {
        $bs = new BodyStructure(
            type: 'IMAGE',
            subtype: 'PNG',
            disposition: 'inline',
        );

        self::assertFalse($bs->isInline());
    }

    public function testFilenameReturnsNullWhenAbsent(): void
    {
        $bs = new BodyStructure('TEXT', 'PLAIN');

        self::assertNull($bs->filename());
        self::assertFalse($bs->isAttachment());
    }

    public function testCharsetReturnsNullWhenAbsent(): void
    {
        self::assertNull(new BodyStructure('TEXT', 'PLAIN')->charset());
    }

    public function testDefaultPartNumberAndEmptyParts(): void
    {
        $bs = new BodyStructure('TEXT', 'PLAIN');

        self::assertSame('1', $bs->partNumber);
        self::assertSame([], $bs->parts);
        self::assertSame(0, $bs->size);
        self::assertNull($bs->encoding);
    }

    public function testMultipartWithChildParts(): void
    {
        $child1 = new BodyStructure('TEXT', 'PLAIN', partNumber: '1');
        $child2 = new BodyStructure('TEXT', 'HTML', partNumber: '2');
        $bs = new BodyStructure(
            type: 'multipart',
            subtype: 'alternative',
            parts: [$child1, $child2],
        );

        self::assertTrue($bs->isMultipart());
        self::assertSame('multipart/alternative', $bs->mimeType());
        self::assertCount(2, $bs->parts);
        self::assertSame($child1, $bs->parts[0]);
        self::assertSame('2', $bs->parts[1]->partNumber);
    }
}
