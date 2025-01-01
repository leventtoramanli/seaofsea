<?php
require_once 'DatabaseHandler.php';

class CRUDHandler {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    // Genel bir sorgu bağlama ve yürütme yöntemi
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

    // READ işlemi (JOIN ve Pagination desteği)
    public function read(
        string $table,
        array $searchs = [],
        array|string $columns = '*',
        string $extras = '',
        bool $fetchAll = false,
        int $page = 1,
        int $itemsPerPage = 10,
        array $joins = []
    ) {
        if (empty($table)) {
            throw new Exception('Table name cannot be empty.');
        }

        // SELECT sütunlarını belirle
        $selectColumns = is_array($columns) ? implode(', ', $columns) : $columns;

        // FROM ve JOIN kısmını oluştur
        $query = "SELECT $selectColumns FROM $table";
        if (!empty($joins)) {
            foreach ($joins as $join) {
                if (!isset($join['type'], $join['table'], $join['on'])) {
                    throw new Exception('JOIN parameters are incomplete.');
                }
                $query .= " {$join['type']} JOIN {$join['table']} ON {$join['on']}";
            }
        }

        // WHERE koşullarını oluştur
        $values = [];
        if (!empty($searchs)) {
            $query .= " WHERE ";
            $conditionStrings = [];
            foreach ($searchs as $key => $value) {
                if (is_array($value)) {
                    $operator = $value['operator'] ?? '=';
                    $conditionStrings[] = "$key $operator ?";
                    $values[] = $value['value'];
                } else {
                    $conditionStrings[] = "$key = ?";
                    $values[] = $value;
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

/*<?php
require_once 'CRUDHandler.php'; // CRUDHandler sınıfını içeri aktar

// Veritabanı bağlantısını oluştur
$dbHandler = new DatabaseHandler();
$crud = new CRUDHandler($dbHandler->getConnection());

// 1. Yeni bir kullanıcı eklemek
// Kullanıcıyı `users` tablosuna ekler ve yeni kullanıcı ID'sini döner.
$newUserId = $crud->create(
    'users',
    [
        'name' => 'Jane Doe',
        'email' => 'jane.doe@example.com',
        'password' => password_hash('securepass', PASSWORD_BCRYPT),
        'is_verified' => 1,
        'role_id' => 2
    ],
    true // ID döndür
);
echo "Yeni kullanıcı ID: $newUserId\n";

// 2. Belirli bir kullanıcıyı email ile getirmek
// Belirtilen e-posta adresine sahip kullanıcıyı getirir.
$user = $crud->read(
    table: 'users',
    searchs: ['email' => 'jane.doe@example.com']
);

if ($user) {
    echo "Kullanıcı adı: {$user['name']}, E-posta: {$user['email']}\n";
} else {
    echo "Kullanıcı bulunamadı.\n";
}

// 3. Tüm kullanıcıları getirmek
// `users` tablosundaki tüm kullanıcıları döner.
$users = $crud->read(
    table: 'users',
    fetchAll: true
);

foreach ($users as $user) {
    echo "ID: {$user['id']}, Ad: {$user['name']}, E-posta: {$user['email']}\n";
}

// 4. İsim ve doğrulama durumuna göre kullanıcıları getirmek
// İsmi "Jane" içeren ve doğrulanmış kullanıcıları getirir.
$filteredUsers = $crud->read(
    table: 'users',
    searchs: [
        ['AND', 
            ['name' => ['operator' => 'LIKE', 'value' => '%Jane%']], 
            ['is_verified' => 1]
        ]
    ],
    fetchAll: true
);

foreach ($filteredUsers as $user) {
    echo "Kullanıcı: {$user['name']} - E-posta: {$user['email']}\n";
}

// 5. Sayfalama (Pagination) ile kullanıcıları getirmek
// Sayfa 2'deki, her sayfada 5 kayıt bulunan kullanıcıları getirir.
$page = 2;
$itemsPerPage = 5;

$paginatedUsers = $crud->read(
    table: 'users',
    searchs: ['is_verified' => 1],
    extras: 'ORDER BY created_at DESC',
    fetchAll: true,
    page: $page,
    itemsPerPage: $itemsPerPage
);

foreach ($paginatedUsers as $user) {
    echo "Sayfa 2 Kullanıcı: {$user['name']} - E-posta: {$user['email']}\n";
}

// 6. Kullanıcı güncelleme
// Kullanıcının adını günceller.
$updateStatus = $crud->update(
    'users',
    ['name' => 'Jane Doe Updated'],
    ['email' => 'jane.doe@example.com']
);

echo $updateStatus ? "Kullanıcı güncellendi.\n" : "Kullanıcı güncellenemedi.\n";

// 7. Kullanıcıyı silmek
// Belirtilen e-posta adresine sahip kullanıcıyı siler.
$deleteStatus = $crud->delete(
    'users',
    ['email' => 'jane.doe@example.com']
);

echo $deleteStatus ? "Kullanıcı silindi.\n" : "Kullanıcı silinemedi.\n";

// 8. Toplam kullanıcı sayısını hesaplamak
// Tüm kullanıcıların toplam sayısını döner.
$totalUsers = $crud->count('users');
echo "Toplam kullanıcı sayısı: $totalUsers\n";

// 9. Doğrulanmış kullanıcıların toplam sayısını hesaplamak
// Doğrulanan kullanıcıların toplam sayısını döner.
$verifiedUsers = $crud->count(
    table: 'users',
    searchs: ['is_verified' => 1]
);

echo "Doğrulanmış kullanıcı sayısı: $verifiedUsers\n";

// 10. JOIN işlemi ile kullanıcı rolleri
// `users` ve `roles` tablolarını birleştirerek kullanıcıların rollerini getirir.
$usersWithRoles = $crud->read(
    table: 'users',
    columns: ['users.name', 'roles.name AS role_name'],
    joins: [
        [
            'type' => 'INNER',
            'table' => 'roles',
            'on' => 'users.role_id = roles.id'
        ]
    ],
    fetchAll: true
);

foreach ($usersWithRoles as $user) {
    echo "Kullanıcı: {$user['name']} - Rol: {$user['role_name']}\n";
}

// 11. Gruplandırma ve sıralama ile logları getirmek
// `logs` tablosunu gruplayarak her bir eylemin toplam sayısını getirir.
$actions = $crud->read(
    table: 'logs',
    columns: ['action', 'COUNT(*) as total'],
    extras: 'GROUP BY action ORDER BY total DESC',
    fetchAll: true
);

foreach ($actions as $action) {
    echo "Eylem: {$action['action']} - Toplam: {$action['total']}\n";
}

// 12. Tarihe göre kullanıcıları filtrelemek
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
*/

?>