<?php
// app/core/Router.php

namespace App\Core;

/**
 * Router — maps URI patterns to controller actions.
 * Reusable by all modules.
 */
class Router
{
    private array $routes = [];

    /**
     * Register a route.
     * @param string          $method  GET|POST|PUT|DELETE|ANY
     * @param string          $path    e.g. '/asset/list'
     * @param callable|array  $handler [$controllerClass, 'method'] or closure
     */
    public function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => $path,
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable|array $handler): void  { $this->add('GET',  $path, $handler); }
    public function post(string $path, callable|array $handler): void { $this->add('POST', $path, $handler); }
    public function any(string $path, callable|array $handler): void  { $this->add('ANY',  $path, $handler); }

    /**
     * Dispatch the current request.
     */
    public function dispatch(): void
    {
        $method  = $_SERVER['REQUEST_METHOD'];
        $uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        // Strip base path (e.g. /itds_oop/public) to get clean path
        $base    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $path    = '/' . ltrim(substr($uri, strlen($base)), '/');
        $path    = $path === '' ? '/' : $path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) continue;

            // Support simple {param} placeholders
            $pattern = preg_replace('#\{[^/]+\}#', '([^/]+)', $route['path']);
            if (preg_match('#^' . $pattern . '$#', $path, $matches)) {
                array_shift($matches); // remove full match
                $handler = $route['handler'];

                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action(...$matches);
                } else {
                    $handler(...$matches);
                }
                return;
            }
        }

        // 404
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Route not found: ' . $path]);
    }
}
