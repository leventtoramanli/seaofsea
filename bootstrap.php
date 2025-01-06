<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/lib/handlers/UserHandler.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Logger oluştur (merkezi kullanım için)
$logger = new Logger('application');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/application.log', Logger::ERROR));

$loggerInfo = new Logger('application_info');
$loggerInfo->pushHandler(new StreamHandler(__DIR__ . '/logs/appInfo.log', Logger::INFO));

// Merkezi hata yönetimi
set_exception_handler(function ($e) use ($logger) {
    $logger->error('Unhandled Exception', ['exception' => $e]);
    http_response_code(500);
    echo "An error occurred. Please try again later.";
});

set_error_handler(function ($severity, $message, $file, $line) use ($logger) {
    $logger->error("Error [{$severity}]: {$message} in {$file} on line {$line}");
    http_response_code(500);
    echo "An error occurred. Please try again later.";
});

// Merkezi Logger erişimi için fonksiyon (isteğe bağlı)
function getLogger(): Logger {
    global $logger;
    return $logger;
}
function getLoggerInfo(): Logger {
    global $loggerInfo;
    return $loggerInfo;
}
new DatabaseHandler();