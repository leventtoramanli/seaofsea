<?php
class Autoloader {
    public static function register() {
        spl_autoload_register(function ($class) {
            $paths = [
                __DIR__ . '/../core/',
                __DIR__ . '/../modules/',
                __DIR__ . '/../modules/auth/',
                __DIR__ . '/../services/',
            ];

            foreach ($paths as $path) {
                $file = $path . $class . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }
}
