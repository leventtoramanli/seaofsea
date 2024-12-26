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
        }
        return $stmt->execute();
    }

    public function read($table, $conditions = []) {
        $query = "SELECT * FROM $table";
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", array_map(function ($key) {
                return "$key=?";
            }, array_keys($conditions)));
        }

        $stmt = $this->db->prepare($query);
        if (!empty($conditions)) {
            $types = str_repeat("s", count($conditions));
            $stmt->bind_param($types, ...array_values($conditions));
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
