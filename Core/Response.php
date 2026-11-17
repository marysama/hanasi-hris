<?php
namespace App\Core;

class Response
{
    public static function redirect(string $url, int $code = 302): void
    {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function back(string $fallback = '/'): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? $fallback;
        self::redirect($ref);
    }

    public static function abort(int $code, string $message = ''): void
    {
        http_response_code($code);
        $msg = htmlspecialchars($message !== '' ? $message : 'Error ' . $code, ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><meta charset='utf-8'><title>Error</title>"
           . "<div style='font-family:system-ui,Arial;padding:40px;text-align:center'>"
           . "<h1 style='color:#fa7a14'>{$code}</h1><p>{$msg}</p></div>";
        exit;
    }
}