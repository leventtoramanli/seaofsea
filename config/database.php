<?php
class Database {
    private $host;
    private $dbname;
    private $user;
    private $pass;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->dbname = getenv('DB_NAME') ?: 'seaofsea_db';
        $this->user = getenv('DB_USER') ?: 'root';
        $this->pass = getenv('DB_PASS') ?: '';


        // Ortam değişkenleri kontrolü
        if (!$this->host || !$this->dbname || !$this->user) {
            throw new \Exception("Database configuration is incomplete. Please check your .env file.");
        }

        try {
            $this->conn = new \PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->user,
                $this->pass
            );
            $this->conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new \Exception("Database connection failed. Please try again later.");
        }
    }
}
