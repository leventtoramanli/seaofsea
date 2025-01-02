<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class DatabaseHandler {
    private static $logger;

    public function __construct() {
        if (!self::$logger) {
            self::$logger = new Logger('database');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/database.log', Logger::ERROR));
        }

        try {
            $capsule = new Capsule;

            $capsule->addConnection([
                'driver' => 'mysql',
                'host' => $_ENV['DB_HOST'],
                'database' => $_ENV['DB_NAME'],
                'username' => $_ENV['DB_USER'],
                'password' => $_ENV['DB_PASSWORD'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);

            $capsule->setAsGlobal();
            $capsule->bootEloquent();
        } catch (\Exception $e) {
            self::$logger->error('Database connection failed', ['exception' => $e]);
            throw new \Exception('Database connection failed: ' . $e->getMessage());
        }
    }
}
