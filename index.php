<?php

if (session_status() == PHP_SESSION_NONE) session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/initializer.php';
require_once 'config/database.php';
require_once 'utils/LoggerHelper.php';
require_once 'middlewares/CorsMiddleware.php';

use App\Utils\LoggerHelper;
use App\Middlewares\CorsMiddleware;

\CorsMiddleware::handle();

LoggerHelper::getLogger()->info("Application started successfully");

echo "Application is running.";
function get_env_var($key, $default = null) {
    return isset($_ENV[$key]) ? $_ENV[$key] : $default;
}

$host = get_env_var('DB_HOST');
$dbname = get_env_var('DB_NAME');
$user = get_env_var('DB_USER');
$pass = get_env_var('DB_PASS');
$jwtSecret = get_env_var('JWT_SECRET');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected successfully.";
} catch (PDOException $e) {
    LoggerHelper::getLogger()->error("Database connection failed: " . $e->getMessage());
    die(json_encode(["status" => "error", "message" => "Database connection failed."]));
}

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
