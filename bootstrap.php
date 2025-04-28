<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/lib/handlers/UserHandler.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// .env dosyasını yükle
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} else {
    error_log("⚠️ Uyarı: `.env` dosyası bulunamadı! Varsayılan değerler kullanılacak.");
}

// Logger oluştur (merkezi kullanım için)
$logger = new Logger('application');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/application.log', Logger::ERROR));

$loggerInfo = new Logger('application_info');
$loggerInfo->pushHandler(new StreamHandler(__DIR__ . '/logs/appInfo.log', Logger::INFO));

// Merkezi hata yönetimi
set_exception_handler(function ($e) use ($logger) {
    $logger->error('Unhandled Exception', ['exception' => $e]);
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An error occurred. Please try again later."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) use ($logger) {
    $logger->error("Error [{$severity}]: {$message} in {$file} on line {$line}");
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An error occurred. Please try again later."], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// Merkezi Logger erişimi için fonksiyon
function getLogger(): Logger {
    global $logger;
    return $logger ?? new Logger('fallback_logger');
}
function getLoggerInfo(): Logger {
    global $loggerInfo;
    return $loggerInfo ?? new Logger('fallback_logger');
}

function getAuthorizationHeader(): ?string {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Nginx or fast CGI
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_change_key_case($requestHeaders, CASE_LOWER);
        if (isset($requestHeaders['authorization'])) {
            $headers = trim($requestHeaders['authorization']);
        }
    }
    return $headers;
}

function getBearerToken(): ?string {
    $authHeader = getAuthorizationHeader();
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }
    return substr($authHeader, 7); // Bearer sonrasını al
}

// Database ve UserHandler başlatma
try {
    new DatabaseHandler();
    $userHandler = new UserHandler();

    // Kullanıcıların süresi dolmuş tokenlerini temizle
    $userHandler->cleanExpiredTokens();
} catch (Exception $e) {
    error_log("⚠️ Database veya UserHandler başlatma hatası: " . $e->getMessage());
}

// JWT Secret yükleme ve kontrol
$jwtSecret = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? null);
if (empty($jwtSecret)) {
    error_log("🚨 JWT Hata: `JWT_SECRET` boş!");
    die(json_encode(["success" => false, "message" => "JWT Secret is missing!"]));
}
