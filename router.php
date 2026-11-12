<?php
declare(strict_types=1);

require __DIR__ . '/api/config.php';

// One entry point for the built-in server: /api/* goes to the API, everything else is a file from public/.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/reset-password.php') {
    $path = '/index.html'; // the shop page shows the reset form
}

if ($path === '/verify.php' || str_starts_with($path, '/api/') || str_starts_with($path, '/analytics/')) {
    require __DIR__ . '/api/index.php';
    return;
}

$upload = preg_match('#^/uploads/(avatars|products)/#', $path, $found) ? $found[1] : null; // only public pictures
$root = realpath($upload ? data_dir() . '/uploads/' . $upload : __DIR__ . '/public');
$file = $root === false ? false : realpath($upload ? data_dir() . $path : $root . ($path === '/' ? '/index.html' : $path));
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript',
          'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($file);