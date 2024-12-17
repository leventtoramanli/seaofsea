<?php
namespace App\Utils;
require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LoggerHelper {
    private static $logger;

    public static function getLogger($name = 'app') {
        if (!self::$logger) {
            self::$logger = new Logger($name);
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
        }
        return self::$logger;
    }
}
