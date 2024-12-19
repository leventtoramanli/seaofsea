<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'vendor/autoload.php';
require_once 'utils/LoggerHelper.php';
require_once 'middlewares/CorsMiddleware.php';
require_once 'config/database.php';
require_once 'utils/LoggerHelper.php';
require_once 'config/initializer.php';

use App\Utils\LoggerHelper;
use Dotenv\Dotenv;



LoggerHelper::getLogger()->info("Application started successfully");

echo "Application is running.";

// CORS Middleware
CorsMiddleware::handle();

// Veritabanı bağlantısı
$host = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];

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
    $response = [
        "status" => "error",
        "message" => "An unexpected error occurred.",
        "error" => $exception->getMessage()
    ];
    App\Utils\LoggerHelper::getLogger()->error($exception->getMessage(), ["trace" => $exception->getTrace()]);
    http_response_code(500);
    echo json_encode($response);
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $response = [
        "status" => "error",
        "message" => "A system error occurred.",
        "error" => $errstr
    ];
    App\Utils\LoggerHelper::getLogger()->error($errstr, ["file" => $errfile, "line" => $errline]);
    http_response_code(500);
    echo json_encode($response);
});

