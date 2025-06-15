<?php

class DB
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $dbConfig = $config['db'];

            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";

            try {
                self::$instance = new PDO(
                    $dsn,
                    $dbConfig['user'],
                    $dbConfig['pass'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection error: ' . $e->getMessage()
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    // Bağlantıyı manuel olarak sıfırlamak istersen
    public static function reset(): void
    {
        self::$instance = null;
    }
}
