<?php

// ==== OUTPUT BUFFER AKTİFLEŞTİR (Beklenmeyen çıktıları yakalamak için) ====
ob_start();

// ==== GEREKLİ HEADER TANIMLARI ====
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

// ==== AUTOLOAD / GEREKLİ DOSYALAR ====
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/core/logger.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/DB.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/JWT.php';
require_once __DIR__ . '/core/Autoloader.php';
Autoloader::register();

if (getenv('APP_ENV') === 'development') {
    print("Environment: development\n");
}


// ==== HATALARI LOG'A YÖNLENDİR (GÖSTERME) ====
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ==== TÜM PHP HATALARI LOGGER İLE YAKALANSIN ====
set_error_handler(function ($severity, $message, $file, $line) {
    Logger::error("PHP Error [$severity] in $file at line $line: $message");
});
set_exception_handler(function ($exception) {
    Logger::error("Uncaught Exception: " . $exception->getMessage() . " in " .
        $exception->getFile() . " at line " . $exception->getLine());
    Response::error("Unexpected server error.", 500);
    exit;
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        Logger::error("Fatal Error [{$error['type']}] in {$error['file']} at line {$error['line']}: {$error['message']}");
        // JSON çıktıyı bozacak beklenmedik HTML varsa sil
        if (ob_get_length()) {
            ob_clean();
        }
        Response::error("Fatal server error.", 500);
    }
});

// ==== JSON GİRİŞ VERİSİNİ AL ====
$rawInput = file_get_contents("php://input");
Logger::info(['rawInput' => $rawInput]);

$input = json_decode($rawInput, true);
if ($input === null) {
    Logger::error([
        'message' => 'JSON Decode Error: ' . json_last_error_msg(),
        'rawInput' => $rawInput
    ]);
    Response::error("Invalid JSON format: " . json_last_error_msg(), 400);
    exit;
}

if (!is_array($input) || !isset($input['module']) || !isset($input['action'])) {
    Logger::error("Invalid request structure: missing module or action");
    Response::error("Invalid request format. JSON expected.", 400);
    exit;
}

// ==== ROUTER'I ÇALIŞTIR ====
Logger::info("Dispatching module: " . $input['module'] . " / action: " . $input['action']);
Router::dispatch($input);
Logger::info("Router dispatch completed");


// ==== BEKLENMEDİK HTML/ÇIKTI VARSA TEMİZLE + LOGA YAZ ====
$output = ob_get_clean();
if (!empty($output)) {
    Logger::error("Unexpected Output: " . $output);
    Response::error("Unexpected server output.", 500);
}
