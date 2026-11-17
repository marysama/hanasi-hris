<?php
namespace App\Exceptions;

class Handler
{
    public static function register(): void
    {
        set_exception_handler(function (\Throwable $e) {
            error_log('[Hanasi HRIS] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $debug = (require __DIR__ . '/../../config/app.php')['debug'] ?? false;
            http_response_code(500);
            echo "<!doctype html><meta charset='utf-8'><title>Error</title>"
               . "<div style='font-family:system-ui,Arial;padding:40px;text-align:center'>"
               . "<h1 style='color:#b3261e'>Terjadi kesalahan</h1>";
            if ($debug) {
                echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
                echo "<pre style='text-align:left;background:#f5f5f5;padding:12px;overflow:auto'>"
                   . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "</pre>";
            } else {
                echo "<p>Silakan hubungi administrator.</p>";
            }
            echo "</div>";
            exit;
        });
    }
}