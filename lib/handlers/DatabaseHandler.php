<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

class DatabaseHandler {
    private static $logger;

    public function __construct() {
        if (!self::$logger) {
            self::$logger = new Logger('database');
            self::$logger->pushHandler(new RotatingFileHandler(__DIR__ . '/../../logs/database.log', 7, Logger::ERROR));
        }

        try {
            self::$logger->info('Initializing database connection.');

            // Gerekli env değişkenlerini kontrol et
            $requiredEnv = ['DB_HOST', 'DB_NAME', 'DB_USER'];
            foreach ($requiredEnv as $key) {
                if (empty($_ENV[$key])) {
                    throw new \Exception("Environment variable {$key} is missing or empty.");
                }
            }

            // Bağlantı bilgilerini hazırla
            $capsule = new Capsule;

            $attempts = 3;
            while ($attempts > 0) {
                try {
                    $capsule->addConnection([
                        'driver' => 'mysql',
                        'host' => $_ENV['DB_HOST'],
                        'database' => $_ENV['DB_NAME'],
                        'username' => $_ENV['DB_USER'],
                        'password' => $_ENV['DB_PASSWORD'] ?? '',
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'prefix' => '',
                    ]);

                    $capsule->setAsGlobal();
                    $capsule->bootEloquent();

                    self::$logger->info('Database connection established successfully.');
                    break;
                } catch (\Exception $e) {
                    $attempts--;
                    if ($attempts === 0) {
                        self::$logger->error('Database connection failed after multiple attempts.', ['exception' => $e]);
                        throw new \Exception('Database connection failed: ' . $e->getMessage());
                    }
                    sleep(1); // Yeniden deneme öncesi bir saniye bekle
                }
            }
        } catch (\Exception $e) {
            self::$logger->error('Database connection failed.', ['exception' => $e]);
            throw $e;
        }
    }

    public static function getConnection() {
        return Capsule::connection();
    }

    public static function testConnection() {
        try {
            $pdo = self::getConnection()->getPdo();
            $query = $pdo->query("SHOW TABLES");
            $tables = $query->fetchAll();

            if (empty($tables)) {
                throw new \Exception('Database connected, but no tables found.');
            }

            return $tables;
        } catch (\Exception $e) {
            self::$logger->error('Test connection failed.', ['exception' => $e]);
            throw $e;
        }
    }
}
