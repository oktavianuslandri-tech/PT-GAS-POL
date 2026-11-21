<?php
declare(strict_types=1);

namespace Warehouse;

final class Config
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read .env configuration file');
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new \RuntimeException('Invalid .env configuration line');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new \RuntimeException('Invalid .env configuration key');
            }
            if (getenv($key) === false) {
                if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                    $value = stripcslashes(substr($value, 1, -1));
                }
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
