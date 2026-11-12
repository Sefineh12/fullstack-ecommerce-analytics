<?php
declare(strict_types=1);

// Local development only: copy .env.example to .env. On Render, set real environment variables instead.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) {
            putenv(trim($key) . '=' . trim($value, " \t\"'"));
        }
    }
}

if (!function_exists('setting')) {
    function setting(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }
}

// The folder that holds the database and uploaded pictures.
// Default: the "storage" folder inside the project. On Render, set DATA_DIR to the persistent disk path.
if (!function_exists('data_dir')) {
    function data_dir(): string
    {
        return rtrim(setting('DATA_DIR', dirname(__DIR__) . '/storage'), '/\\');
    }
}

if (!function_exists('db_path')) {
    function db_path(): string
    {
        return data_dir() . '/shop.db';
    }
}