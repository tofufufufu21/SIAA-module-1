<?php
// app/core/BaseController.php

namespace App\Core;

/**
 * BaseController — thin HTTP layer.
 * Controllers ONLY: parse request, call service, return response.
 * No business logic here.
 */
abstract class BaseController
{
    protected array $appConfig;

    public function __construct()
    {
        $this->appConfig = require __DIR__ . '/../../config/app.php';
        $this->setHeaders();
    }

    private function setHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    // ── Request helpers ──────────────────────────────────────

    protected function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    protected function getBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded ?? [];
        }
        return $_POST;
    }

    protected function getQuery(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    protected function requireMethod(string ...$methods): void
    {
        if (!in_array($this->getMethod(), $methods, true)) {
            $this->error('Method not allowed.', 405);
        }
    }

    protected function currentUserId(): int
    {
        // Replace with session: return (int) ($_SESSION['user_id'] ?? 1);
        return (int) ($this->appConfig['current_user_id'] ?? 1);
    }

    // ── Response helpers ─────────────────────────────────────

    protected function success(mixed $data = null, string $message = 'Success', int $code = 200): never
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function created(mixed $data = null, string $message = 'Created'): never
    {
        $this->success($data, $message, 201);
    }

    protected function error(string $message, int $code = 400, mixed $errors = null): never
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function notFound(string $message = 'Resource not found.'): never
    {
        $this->error($message, 404);
    }
}
