<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// === Fallback .env yükleyici ===
if (!function_exists('loadEnvFallback')) {
    function loadEnvFallback(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// === .env Yükleme ===
$basePath = realpath(__DIR__ . '/../..');
$envPath = $basePath . '/.env';

if (file_exists($envPath)) {
    try {
        Dotenv\Dotenv::createImmutable($basePath)->load();
    } catch (Throwable $e) {
        loadEnvFallback($envPath); // Yedekleme
    }
} else {
    loadEnvFallback($envPath);
}

// === CONFIG DEĞERLERİ ===
return [
    'db' => [
        'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'name'     => $_ENV['DB_NAME'] ?? 'seaofsea_db',
        'user'     => $_ENV['DB_USER'] ?? 'root',
        'pass'     => $_ENV['DB_PASSWORD'] ?? '',
        'port'     => $_ENV['DB_PORT'] ?? 3306,
        'charset'  => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
    'jwt' => [
        'secret'     => $_ENV['JWT_SECRET'] ?? 'default_secret_key',
        'expiration' => (int)($_ENV['JWT_EXPIRATION'] ?? 86400), // saniye cinsinden
    ],
    'mail' => [
        'host'         => $_ENV['MAIL_HOST'] ?? 'smtp.example.com',
        'username'     => $_ENV['MAIL_USERNAME'] ?? 'user@example.com',
        'password'     => $_ENV['MAIL_PASSWORD'] ?? '',
        'port'         => (int)($_ENV['MAIL_PORT'] ?? 465),
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com',
        'from_name'    => $_ENV['MAIL_FROM_NAME'] ?? 'SeaOfSea',
    ],
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'url' => $_ENV['APP_URL'] ?? 'http://localhost/seaofsea',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    ],
    'meta' => [
        'application_status' => ['draft','submitted','under_review','shortlisted','interview','offered','hired','rejected','withdrawn'],
        'job_post_status'    => ['draft','published','closed','archived'],
        'job_post_visibility'=> ['public','followers','private'],
        'company_visibility' => ['visible','hidden'],
        'areas'              => ['crew','office','port','shipyard','supplier','agency'],
    ],
];
