<?php
// bootstrap/app.php
// Single entry point for the application. Required by public/index.php.

define('BASE_PATH', dirname(__DIR__));

// ── PSR-4-style autoloader ───────────────────────────────────
spl_autoload_register(function (string $class): void {
    // Map namespace prefixes to directories
    $map = [
        'App\\Core\\'         => BASE_PATH . '/app/core/',
        'App\\Models\\'       => BASE_PATH . '/app/models/',
        'App\\Repositories\\' => BASE_PATH . '/app/repositories/',
        'App\\Services\\'     => BASE_PATH . '/app/services/',
        'App\\Controllers\\'  => BASE_PATH . '/app/controllers/',
        'App\\Helpers\\'      => BASE_PATH . '/app/helpers/',
    ];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file     = $dir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ── Error handling ───────────────────────────────────────────
set_exception_handler(function (\Throwable $e): void {
    $code = method_exists($e, 'getCode') && $e->getCode() >= 400 ? $e->getCode() : 500;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
