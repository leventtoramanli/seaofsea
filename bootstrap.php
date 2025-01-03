<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/vendor/autoload.php';
echo "Vendor is accessible.";
use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Varsayılan zaman dilimini ayarla
//date_default_timezone_set('UTC'); // Gereksiniminize göre değiştirebilirsiniz

// Hata ve istisna yönetimi
if ($_ENV['APP_ENV'] === 'production') {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

set_exception_handler(function ($e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo "An error occurred. Please try again later. Exception! " . $e->getMessage();
});

set_error_handler(function ($severity, $message, $file, $line) {
    error_log("Error [{$severity}]: {$message} in {$file} on line {$line}");
    http_response_code(500);
    echo "An error occurred. Please try again later. Error! " . $message . " in " . $file . " on line " . $line;
});
