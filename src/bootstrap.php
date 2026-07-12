<?php

declare(strict_types=1);

use App\App;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

// --- Configuration ------------------------------------------------------

$configFile = APP_ROOT . '/config.php';

if (!is_file($configFile) || !is_readable($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "php-todo is not configured yet.\n\n"
        . "Copy config.sample.php to config.php next to it, fill in your database\n"
        . "and mail settings, then reload this page. See the README for details.\n";
    exit;
}

$config = require $configFile;

if (!is_array($config) && isset($host, $dbname, $db_username, $db_password, $base_url, $from_email)) {
    // 0.96-style config.php (loose variables). Adapt it so upgrades work
    // without rewriting the config; new installs should use the array format.
    $config = [
        'db' => [
            'host' => $host,
            'name' => $dbname,
            'user' => $db_username,
            'pass' => $db_password,
        ],
        'base_url' => $base_url,
        'mail' => [
            'from'      => $from_email,
            'from_name' => 'To-Do App',
            'transport' => 'mail',
        ],
    ];
}

if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "config.php must return an array. Use config.sample.php as a starting point.\n";
    exit;
}

App::init($config);

// --- Error handling -----------------------------------------------------

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[php-todo] Uncaught %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: an unexpected problem occurred. See the server error log.\n");
        exit(1);
    }

    http_response_code(500);

    // JSON for AJAX endpoints, HTML for everything else.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (str_contains($accept, 'application/json') || strcasecmp($requestedWith, 'XMLHttpRequest') === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Server error.']);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head>'
        . '<body style="font-family:Arial,sans-serif;background:#121212;color:#c0c0c0;text-align:center;padding-top:10vh">'
        . '<h1 style="color:#00ddff">Something went wrong</h1>'
        . '<p>An unexpected error occurred. Details have been written to the server error log.</p>'
        . '</body></html>';
});

// --- Session ------------------------------------------------------------

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
