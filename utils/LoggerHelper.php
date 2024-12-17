<?php
namespace App\Utils;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class LoggerHelper {
    private static $logger;

    public static function getLogger($name = 'app') {
        if (!self::$logger) {
            self::$logger = new Logger($name);

            // Log dosyasını günlük olarak döndür
            $logPath = __DIR__ . '/../logs/app.log';
            $handler = new RotatingFileHandler($logPath, 7, Logger::DEBUG); // Maksimum 7 gün
            self::$logger->pushHandler($handler);
        }

        return self::$logger;
    }
}
