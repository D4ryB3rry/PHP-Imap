<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Mime;

class HeaderDecoder
{
    public static function decode(string $value): string
    {
        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            function (array $matches) {
                $charset = $matches[1];
                $encoding = strtoupper($matches[2]);
                $text = $matches[3];

                $decoded = $encoding === 'B'
                    ? base64_decode($text, true)
                    : quoted_printable_decode(str_replace('_', ' ', $text));

                if ($decoded === false) {
                    return $matches[0];
                }

                return self::convertToUtf8($decoded, $charset);
            },
            $value,
        );

        // Remove whitespace between adjacent encoded words
        $decoded = preg_replace('/\?=\s+=\?/', '?==?', $decoded);

        return $decoded;
    }

    /**
     * @return array<string, string[]>
     */
    public static function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $headerBlock = str_replace("\r\n", "\n", $headerBlock);

        // Unfold headers (continuation lines start with whitespace)
        $headerBlock = preg_replace('/\n([ \t]+)/', ' ', $headerBlock);

        $lines = explode("\n", $headerBlock);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));
            $value = self::decode($value);

            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return $headers;
    }

    public static function parseContentType(string $value): array
    {
        $parts = explode(';', $value);
        $mimeType = strtolower(trim($parts[0]));
        $params = [];

        for ($i = 1, $iMax = count($parts); $i < $iMax; $i++) {
            $param = trim($parts[$i]);
            $eqPos = strpos($param, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = strtolower(trim(substr($param, 0, $eqPos)));
            $val = trim(substr($param, $eqPos + 1));

            // Handle RFC 2231 parameter continuations
            if (str_ends_with($key, '*')) {
                $key = rtrim($key, '*');
                $val = self::decodeRfc2231Value($val);
            } else {
                $val = trim($val, '"');
            }

            $params[$key] = $val;
        }

        return ['type' => $mimeType, 'params' => $params];
    }

    public static function parseContentDisposition(string $value): array
    {
        $parts = explode(';', $value);
        $disposition = strtolower(trim($parts[0]));
        $params = [];

        for ($i = 1, $iMax = count($parts); $i < $iMax; $i++) {
            $param = trim($parts[$i]);
            $eqPos = strpos($param, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = strtolower(trim(substr($param, 0, $eqPos)));
            $val = trim(substr($param, $eqPos + 1));

            if (str_ends_with($key, '*')) {
                $key = rtrim($key, '*');
                $val = self::decodeRfc2231Value($val);
            } else {
                $val = trim($val, '"');
            }

            $params[$key] = $val;
        }

        return ['disposition' => $disposition, 'params' => $params];
    }

    private static function decodeRfc2231Value(string $value): string
    {
        // Format: charset'language'encoded_value
        if (preg_match("/^([^']*)'([^']*)'(.*)$/", $value, $matches)) {
            $charset = $matches[1];
            $encoded = $matches[3];
            $decoded = rawurldecode($encoded);

            return self::convertToUtf8($decoded, $charset);
        }

        return rawurldecode($value);
    }

    public static function convertToUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));

        if ($charset === 'utf-8' || $charset === 'us-ascii' || $charset === 'ascii') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return $converted !== false ? $converted : $text;
    }
}
