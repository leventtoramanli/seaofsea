<?php
require_once 'vendor/autoload.php';
require_once 'utils/LoggerHelper.php';
require_once 'middlewares/CorsMiddleware.php';

use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// CORS Middleware
CorsMiddleware::handle();

// Veritabanı bağlantısı
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected successfully.";
} catch (PDOException $e) {
    LoggerHelper::getLogger()->error("Database connection failed: " . $e->getMessage());
    die(json_encode(["status" => "error", "message" => "Database connection failed."]));
}

// Global hata yöneticisi
set_exception_handler(function ($exception) {
    LoggerHelper::getLogger()->error($exception->getMessage(), ["trace" => $exception->getTrace()]);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "An unexpected error occurred."]);
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    LoggerHelper::getLogger()->error($errstr, ["file" => $errfile, "line" => $errline]);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "A system error occurred."]);
});
