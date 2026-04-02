<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Mime;

use D4ry\ImapClient\Enum\ContentTransferEncoding;
use D4ry\ImapClient\Mime\Contract\MimeParserInterface;

class MimeParser implements MimeParserInterface
{
    public function parse(string $rawMessage): ParsedMessage
    {
        [$headerBlock, $body] = $this->splitHeaderBody($rawMessage);

        $headers = HeaderDecoder::parseHeaders($headerBlock);
        $contentType = $this->getContentType($headers);

        $textBody = null;
        $htmlBody = null;
        $parts = [];

        if (str_starts_with($contentType['type'], 'multipart/')) {
            $boundary = $contentType['params']['boundary'] ?? null;
            if ($boundary !== null) {
                $parsedParts = $this->parseMultipart($body, $boundary);
                foreach ($parsedParts as $part) {
                    if ($part->mimeType === 'text/plain' && $part->filename === null && $textBody === null) {
                        $textBody = $part->content;
                    } elseif ($part->mimeType === 'text/html' && $part->filename === null && $htmlBody === null) {
                        $htmlBody = $part->content;
                    } else {
                        $parts[] = $part;
                    }
                }
            }
        } else {
            $encoding = $this->getTransferEncoding($headers);
            $charset = $contentType['params']['charset'] ?? 'UTF-8';
            $decoded = $this->decodeContent($body, $encoding);
            $decoded = HeaderDecoder::convertToUtf8($decoded, $charset);

            if ($contentType['type'] === 'text/html') {
                $htmlBody = $decoded;
            } else {
                $textBody = $decoded;
            }
        }

        return new ParsedMessage(
            headers: $headers,
            textBody: $textBody,
            htmlBody: $htmlBody,
            parts: $parts,
        );
    }

    /**
     * @return ParsedPart[]
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $parts = [];
        $delimiter = '--' . $boundary;
        $endDelimiter = '--' . $boundary . '--';

        $sections = explode($delimiter, $body);

        // First section is preamble, skip it. Last might be epilogue after --boundary--
        for ($i = 1, $count = count($sections); $i < $count; $i++) {
            $section = $sections[$i];

            if (str_starts_with(trim($section), '--')) {
                break; // End delimiter
            }

            // Remove leading \r\n
            if (str_starts_with($section, "\r\n")) {
                $section = substr($section, 2);
            } elseif (str_starts_with($section, "\n")) {
                $section = substr($section, 1);
            }

            // Remove trailing \r\n
            $section = rtrim($section, "\r\n");

            $parsedParts = $this->parsePart($section);
            foreach ($parsedParts as $part) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @return ParsedPart[]
     */
    private function parsePart(string $rawPart): array
    {
        [$headerBlock, $body] = $this->splitHeaderBody($rawPart);
        $headers = HeaderDecoder::parseHeaders($headerBlock);
        $contentType = $this->getContentType($headers);

        if (str_starts_with($contentType['type'], 'multipart/')) {
            $boundary = $contentType['params']['boundary'] ?? null;
            if ($boundary !== null) {
                return $this->parseMultipart($body, $boundary);
            }
        }

        $encoding = $this->getTransferEncoding($headers);
        $decoded = $this->decodeContent($body, $encoding);

        $charset = $contentType['params']['charset'] ?? null;
        if ($charset !== null && str_starts_with($contentType['type'], 'text/')) {
            $decoded = HeaderDecoder::convertToUtf8($decoded, $charset);
        }

        $disposition = $this->getContentDisposition($headers);
        $filename = $disposition['params']['filename']
            ?? $contentType['params']['name']
            ?? null;

        $isInline = ($disposition['disposition'] ?? '') === 'inline';
        $contentId = $this->getHeaderValue($headers, 'Content-ID');
        if ($contentId !== null) {
            $contentId = trim($contentId, '<>');
        }

        return [
            new ParsedPart(
                mimeType: $contentType['type'],
                content: $decoded,
                filename: $filename,
                charset: $charset,
                isInline: $isInline,
                contentId: $contentId,
                encoding: $encoding,
            ),
        ];
    }

    /**
     * @return array{string, string}
     */
    private function splitHeaderBody(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos !== false) {
            return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
        }

        $pos = strpos($raw, "\n\n");
        if ($pos !== false) {
            return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
        }

        return [$raw, ''];
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function getContentType(array $headers): array
    {
        $value = $this->getHeaderValue($headers, 'Content-Type');
        if ($value === null) {
            return ['type' => 'text/plain', 'params' => ['charset' => 'us-ascii']];
        }

        return HeaderDecoder::parseContentType($value);
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function getContentDisposition(array $headers): array
    {
        $value = $this->getHeaderValue($headers, 'Content-Disposition');
        if ($value === null) {
            return ['disposition' => null, 'params' => []];
        }

        return HeaderDecoder::parseContentDisposition($value);
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function getTransferEncoding(array $headers): ContentTransferEncoding
    {
        $value = $this->getHeaderValue($headers, 'Content-Transfer-Encoding');
        if ($value === null) {
            return ContentTransferEncoding::SevenBit;
        }

        return ContentTransferEncoding::tryFrom(strtolower(trim($value))) ?? ContentTransferEncoding::SevenBit;
    }

    private function decodeContent(string $content, ContentTransferEncoding $encoding): string
    {
        return match ($encoding) {
            ContentTransferEncoding::Base64 => base64_decode(str_replace(["\r", "\n"], '', $content), true) ?: '',
            ContentTransferEncoding::QuotedPrintable => quoted_printable_decode($content),
            default => $content,
        };
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function getHeaderValue(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
