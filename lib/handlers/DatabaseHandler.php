<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class DatabaseHandler {
    private static $logger;

    public function __construct() {
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }

        try {
            self::$logger->info('Initializing database connection.');

            $requiredEnv = ['DB_HOST', 'DB_NAME', 'DB_USER'];
            foreach ($requiredEnv as $key) {
                if (empty($_ENV[$key])) {
                    throw new \Exception("Environment variable {$key} is missing or empty.");
                }
            }

            $capsule = new Capsule;

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
            $tables = $query->fetchAll(PDO::FETCH_ASSOC);
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
