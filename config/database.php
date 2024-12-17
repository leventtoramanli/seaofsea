<?php
use PDO;
use PDOException;

class Database {
    private $host;
    private $dbname;
    private $user;
    private $pass;
    public $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST');
        $this->dbname = getenv('DB_NAME');
        $this->user = getenv('DB_USER');
        $this->pass = getenv('DB_PASS');

        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname}",
                $this->user,
                $this->pass
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
}
