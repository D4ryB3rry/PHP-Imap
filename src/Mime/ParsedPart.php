<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Mime;

use D4ry\ImapClient\Enum\ContentTransferEncoding;

readonly class ParsedPart
{
    public function __construct(
        public string $mimeType,
        public string $content,
        public ?string $filename = null,
        public ?string $charset = null,
        public bool $isInline = false,
        public ?string $contentId = null,
        public ContentTransferEncoding $encoding = ContentTransferEncoding::SevenBit,
    ) {
    }
}
