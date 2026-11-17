<?php
namespace App\Core;

class View
{
    public static function render(string $template, array $data = [], ?string $layout = null): void
    {
        $templatePath = __DIR__ . '/../../resources/views/' . $template . '.php';
        if (!is_file($templatePath)) {
            Response::abort(500, 'View tidak ditemukan: ' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        if ($layout !== null) {
            $layoutPath = __DIR__ . '/../../resources/views/layouts/' . $layout . '.php';
            if (!is_file($layoutPath)) {
                Response::abort(500, 'Layout tidak ditemukan: ' . $layout);
            }
            require $layoutPath;
        } else {
            echo $content;
        }
    }
}