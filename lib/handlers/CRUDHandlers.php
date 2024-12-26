<?php
require_once 'DatabaseHandler.php';

class CRUDHandler {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    public function create($table, $data, $returnId = false) {
        $columns = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));
        $values = array_values($data);

        $query = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->db->prepare($query);

        $types = str_repeat("s", count($values));
        $stmt->bind_param($types, ...$values);
        if($stmt->execute()) {
            if ($returnId) {
                return $this->db->insert_id;
            }
            return true;
        }
        return false;
    }

    public function read($table, $conditions, $fetchAll = false) {
        if (empty($conditions)) {
            throw new Exception('Conditions array cannot be empty.');
        }
    
        $query = "SELECT * FROM $table WHERE ";
        $query .= implode(" AND ", array_map(fn($key) => "$key = ?", array_keys($conditions)));
        $values = array_values($conditions);
    
        try {
            $stmt = $this->db->prepare($query);
            $types = str_repeat("s", count($values));
            $stmt->bind_param($types, ...$values);
    
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                return $fetchAll ? $result->fetch_all(MYSQLI_ASSOC) : $result->fetch_assoc();
            }
    
            return $fetchAll ? [] : null;
        } catch (mysqli_sql_exception $e) {
            throw new Exception('Database query error: ' . $e->getMessage());
        }
    }
    

    public function update($table, $data, $conditions) {
        $setClause = implode(", ", array_map(function ($key) {
            return "$key=?";
        }, array_keys($data)));

        $conditionClause = implode(" AND ", array_map(function ($key) {
            return "$key=?";
        }, array_keys($conditions)));

        $query = "UPDATE $table SET $setClause WHERE $conditionClause";
        $stmt = $this->db->prepare($query);

        $types = str_repeat("s", count($data) + count($conditions));
        $values = array_merge(array_values($data), array_values($conditions));
        $stmt->bind_param($types, ...$values);
        return $stmt->execute();
    }

    public function delete($table, $conditions) {
        $conditionClause = implode(" AND ", array_map(function ($key) {
            return "$key=?";
        }, array_keys($conditions)));

        $query = "DELETE FROM $table WHERE $conditionClause";
        $stmt = $this->db->prepare($query);

        $types = str_repeat("s", count($conditions));
        $stmt->bind_param($types, ...array_values($conditions));
        return $stmt->execute();
    }
}
?>
