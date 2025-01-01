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

    public function read(
        string $table,
        array $searchs = [],
        array|string $columns = '*',
        string $extras = '',
        bool $fetchAll = false
    ) {
        if (empty($table)) {
            throw new Exception('Table name cannot be empty.');
        }
    
        // SELECT sütunlarını belirle
        $selectColumns = is_array($columns) ? implode(', ', $columns) : $columns;
    
        // Sorguyu oluştur
        $query = "SELECT $selectColumns FROM $table";
        $values = [];
        if (!empty($searchs)) {
            $query .= " WHERE ";
            $conditionStrings = [];
    
            foreach ($searchs as $condition) {
                if (is_array($condition)) {
                    // Mantıksal bağlaçlı grup: ['OR', ['key1' => 'value1'], ['key2' => 'value2']]
                    if (isset($condition[0]) && in_array(strtoupper($condition[0]), ['AND', 'OR'])) {
                        $logicalOperator = strtoupper($condition[0]);
                        $groupConditions = array_slice($condition, 1);
    
                        $groupStrings = [];
                        foreach ($groupConditions as $key => $value) {
                            if (is_array($value)) {
                                $operator = $value['operator'] ?? '=';
                                $groupStrings[] = "$key $operator ?";
                                $values[] = $value['value'];
                            } else {
                                $groupStrings[] = "$key = ?";
                                $values[] = $value;
                            }
                        }
    
                        $conditionStrings[] = "(" . implode(" $logicalOperator ", $groupStrings) . ")";
                    } else {
                        throw new Exception('Invalid logical operator in conditions.');
                    }
                } else {
                    throw new Exception('Condition format is invalid.');
                }
            }
    
            $query .= implode(" AND ", $conditionStrings);
        }
    
        // Ek SQL ifadelerini ekle
        if (!empty($extras)) {
            $query .= " " . $extras;
        }
    
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
    /*
    $user = $this->crud->read(
    table: 'users',
    searchs: [
        ['AND', ['email' => 'example@example.com'], ['password' => '123123']]
    ]
    );
    $users = $this->crud->read(
    table: 'users',
    searchs: [
        ['OR', ['name' => ['operator' => 'LIKE', 'value' => '%John%']], ['surname' => 'Doe']]
    ]
    );
    $users = $this->crud->read(
    table: 'users',
    searchs: [
        ['AND', 
            ['email' => 'example@example.com'], 
            ['OR', 
                ['name' => ['operator' => 'LIKE', 'value' => '%John%']], 
                ['surname' => 'Doe']
            ]
        ]
    ],
    columns: ['id', 'name', 'email'],
    extras: 'LIMIT 5'
    );
    $users = $this->crud->read(
    table: 'users',
    searchs: [
        ['OR', ['name' => 'John'], ['surname' => 'Doe']]
    ],
    extras: 'ORDER BY created_at DESC LIMIT 10',
    fetchAll: true
    );
    $page = 1; // Şu anki sayfa
    $itemsPerPage = 10; // Her sayfada gösterilecek öğe sayısı
    $offset = ($page - 1) * $itemsPerPage;

    $users = $this->crud->read(
        table: 'users',
        searchs: [
            ['is_verified' => 1]
        ],
        columns: ['id', 'name', 'email'],
        extras: "ORDER BY created_at DESC LIMIT $itemsPerPage OFFSET $offset",
        fetchAll: true
    );
    */    

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
