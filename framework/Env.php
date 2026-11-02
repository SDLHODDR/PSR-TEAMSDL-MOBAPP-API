<?php

declare(strict_types=1);

namespace SDL\Framework;

/**
 * Minimal .env loader for simple KEY=value files.
 * Existing process/Apache environment variables take precedence.
 * Do not use this as a general-purpose dotenv parser for complex values.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Preserve values already provided by Apache/Windows environment.
            if (getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Simple inline comments are recognized only when preceded by whitespace.
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
