<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader and, if present, a tests/.env file with
 * provider credentials for the integration tests. Variables already set
 * in the real environment take precedence over the file.
 */

require __DIR__ . '/../vendor/autoload.php';

(static function (string $path): void {
    if (! is_file($path) || ! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Allow leading "export " for shell-compatible files.
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $name = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($name === '' || ! preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
            continue;
        }

        // Strip surrounding single or double quotes.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Real environment wins — never overwrite explicit values.
        if (array_key_exists($name, $_ENV) || getenv($name) !== false) {
            continue;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
})(__DIR__ . '/.env');
