<?php
require_once 'DatabaseHandler.php';

class CRUDHandler {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    // Genel bir sorgu bağlama yöntemi
    private function bindAndExecute($query, $values, $fetchAll = false) {
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

    // CREATE işlemi
    public function create($table, $data, $returnId = false) {
        $columns = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));
        $values = array_values($data);

        $query = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->db->prepare($query);

        $types = str_repeat("s", count($values));
        $stmt->bind_param($types, ...$values);

        if ($stmt->execute()) {
            return $returnId ? $this->db->insert_id : true;
        }

        return false;
    }

    // READ işlemi
    public function read(
        string $table,
        array $searchs = [],
        array|string $columns = '*',
        string $extras = '',
        bool $fetchAll = false,
        int $page = 1,
        int $itemsPerPage = 10
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

        // Pagination desteği
        $offset = ($page - 1) * $itemsPerPage;
        $query .= " $extras LIMIT $itemsPerPage OFFSET $offset";

        return $this->bindAndExecute($query, $values, $fetchAll);
    }

    // UPDATE işlemi
    public function update($table, $data, $conditions) {
        $setClause = implode(", ", array_map(fn($key) => "$key=?", array_keys($data)));
        $conditionClause = implode(" AND ", array_map(fn($key) => "$key=?", array_keys($conditions)));

        $query = "UPDATE $table SET $setClause WHERE $conditionClause";
        $values = array_merge(array_values($data), array_values($conditions));

        return $this->bindAndExecute($query, $values);
    }

    // DELETE işlemi
    public function delete($table, $conditions) {
        $conditionClause = implode(" AND ", array_map(fn($key) => "$key=?", array_keys($conditions)));

        $query = "DELETE FROM $table WHERE $conditionClause";
        $values = array_values($conditions);

        return $this->bindAndExecute($query, $values);
    }

    // Toplam kayıt sayısını hesaplama (Pagination için)
    public function count(string $table, array $searchs = []): int {
        $query = "SELECT COUNT(*) as total FROM $table";
        $values = [];

        if (!empty($searchs)) {
            $query .= " WHERE ";
            $conditionStrings = [];
            foreach ($searchs as $key => $value) {
                $conditionStrings[] = "$key = ?";
                $values[] = $value;
            }
            $query .= implode(" AND ", $conditionStrings);
        }

        $result = $this->bindAndExecute($query, $values);
        return $result['total'] ?? 0;
    }
}
/*
// Yeni bir kullanıcı oluşturur ve oluşturulan kullanıcının ID'sini döner.
$newUserId = $crud->create(
    'users',
    [
        'name' => 'Jane',
        'email' => 'jane.doe@example.com',
        'password' => password_hash('securepass', PASSWORD_BCRYPT),
        'is_verified' => 1,
        'role_id' => 2
    ],
    true // Yeni kullanıcının ID'sini döndür
);

echo "Yeni kullanıcı ID: $newUserId";

// Belirtilen e-posta adresine sahip kullanıcıyı getirir.
$user = $crud->read(
    table: 'users',
    searchs: ['email' => 'jane.doe@example.com']
);

if ($user) {
    echo "Kullanıcı adı: {$user['name']}, E-posta: {$user['email']}";
} else {
    echo "Kullanıcı bulunamadı.";
}

// Veritabanındaki tüm kullanıcıları döner.
$users = $crud->read(
    table: 'users',
    fetchAll: true
);

foreach ($users as $user) {
    echo "ID: {$user['id']}, Ad: {$user['name']}, E-posta: {$user['email']}\n";
}

// İsmi "Jane" içeren ve doğrulanmış kullanıcıları getirir.
$users = $crud->read(
    table: 'users',
    searchs: [
        ['AND', 
            ['name' => ['operator' => 'LIKE', 'value' => '%Jane%']], 
            ['is_verified' => 1]
        ]
    ],
    fetchAll: true
);

foreach ($users as $user) {
    echo "Kullanıcı: {$user['name']} - E-posta: {$user['email']}\n";
}

// Sayfalama kullanarak kullanıcıları getirir.
$page = 2; // İkinci sayfa
$itemsPerPage = 5; // Her sayfada 5 kayıt

$paginatedUsers = $crud->read(
    table: 'users',
    searchs: ['is_verified' => 1],
    extras: 'ORDER BY created_at DESC',
    fetchAll: true,
    page: $page,
    itemsPerPage: $itemsPerPage
);

foreach ($paginatedUsers as $user) {
    echo "Ad: {$user['name']}, E-posta: {$user['email']}\n";
}

// Belirtilen e-posta adresine sahip kullanıcının adını günceller.
$updateStatus = $crud->update(
    'users',
    ['name' => 'Jane Doe Updated'],
    ['email' => 'jane.doe@example.com']
);

echo $updateStatus ? "Kullanıcı güncellendi." : "Kullanıcı güncellenemedi.";

// Belirtilen ID'ye sahip kullanıcının adını ve doğrulama durumunu günceller.
$updateStatus = $crud->update(
    'users',
    [
        'name' => 'Jane Updated Again',
        'is_verified' => 0
    ],
    ['id' => $newUserId]
);

echo $updateStatus ? "Güncelleme başarılı." : "Güncelleme başarısız.";

// Belirtilen e-posta adresine sahip kullanıcıyı siler.
$deleteStatus = $crud->delete(
    'users',
    ['email' => 'jane.doe@example.com']
);

echo $deleteStatus ? "Kullanıcı silindi." : "Kullanıcı silinemedi.";

// Belirtilen kullanıcı ID'sine ait logları siler.
$deleteStatus = $crud->delete(
    'logs',
    ['action' => 'User Registered', 'user_id' => $newUserId]
);

echo $deleteStatus ? "Log silindi." : "Log silinemedi.";

// Tüm kullanıcıların toplam sayısını getirir.
$totalUsers = $crud->count('users');
echo "Toplam kullanıcı sayısı: $totalUsers";

// Doğrulanmış kullanıcıların toplam sayısını getirir.
$verifiedUsers = $crud->count(
    table: 'users',
    searchs: ['is_verified' => 1]
);

echo "Doğrulanmış kullanıcı sayısı: $verifiedUsers";

// Her bir eylemi gruplayarak toplam sayısını döner.
$actions = $crud->read(
    table: 'logs',
    columns: ['action', 'COUNT(*) as total'],
    extras: 'GROUP BY action ORDER BY total DESC',
    fetchAll: true
);

foreach ($actions as $action) {
    echo "Eylem: {$action['action']} - Toplam: {$action['total']}\n";
}

// ID'si 1, 2, veya 3 olan kullanıcıları getirir.
$users = $crud->read(
    table: 'users',
    searchs: [
        ['id' => ['operator' => 'IN', 'value' => '(1, 2, 3)']]
    ],
    fetchAll: true
);

foreach ($users as $user) {
    echo "Kullanıcı: {$user['name']} - ID: {$user['id']}\n";
}

// 2025-01-01 tarihinden sonra oluşturulan kullanıcıları getirir.
$recentUsers = $crud->read(
    table: 'users',
    searchs: [
        'created_at' => ['operator' => '>=', 'value' => '2025-01-01']
    ],
    fetchAll: true
);

foreach ($recentUsers as $user) {
    echo "Yeni Kullanıcı: {$user['name']} - Tarih: {$user['created_at']}\n";
}



