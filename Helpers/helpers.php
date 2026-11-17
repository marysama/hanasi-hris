<?php

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        return \App\Core\App::baseUrl() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url(string $portal, string $page = '', array $extra = []): string
    {
        $params = array_merge(['portal' => $portal], $page !== '' ? ['page' => $page] : [], $extra);
        return '?' . http_build_query($params);
    }
}

if (!function_exists('old')) {
    function old(string $key, $default = ''): string
    {
        return e($_SESSION['_old'][$key] ?? $default);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \App\Core\Csrf::field();
    }
}