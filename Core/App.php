<?php
namespace App\Core;

class App
{
    public static function boot(): void
    {
        $config = require __DIR__ . '/../../config/app.php';
        date_default_timezone_set($config['timezone'] ?? 'Asia/Jakarta');
        Session::start();
        Autoloader::register();
    }

    public static function baseUrl(): string
    {
        $config = require __DIR__ . '/../../config/app.php';
        return rtrim($config['url'] ?? '', '/');
    }
}