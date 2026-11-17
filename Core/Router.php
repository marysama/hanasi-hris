<?php
namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => '/' . trim($path, '/'),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(): void
    {
        $method = Request::method();
        $uri = Request::path();

        // Ambil URI murni tanpa query string
        $uri = explode('?', $uri)[0];
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;
            $pattern = $this->buildPattern($route['path']);
            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($route['middleware'] as $mw) {
                    $this->runMiddleware($mw);
                }
                $this->runHandler($route['handler'], $params);
                return;
            }
        }
        Response::abort(404, 'Halaman tidak ditemukan.');
    }

    private function buildPattern(string $path): string
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    private function runMiddleware(string|array $mw): void
    {
        if (is_array($mw)) {
            $class = $mw[0];
            $method = $mw[1] ?? 'handle';
            (new $class())->$method();
            return;
        }
        $class = $mw;
        (new $class())->handle();
    }

    private function runHandler(callable|array $handler, array $params = []): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            call_user_func_array([new $class(), $method], $params);
        }
    }
}