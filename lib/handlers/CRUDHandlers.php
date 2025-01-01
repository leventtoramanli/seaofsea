<?php
require_once 'DatabaseHandler.php';

class CRUDHandler {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    // Genel bir sorgu bağlama ve yürütme yöntemi
    private function bindAndExecute($query, $values = [], $fetchAll = false) {
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($values);

            if (stripos($query, 'SELECT') === 0) {
                return $fetchAll ? $stmt->fetchAll() : $stmt->fetch();
            }

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception('Database query error: ' . $e->getMessage());
        }
    }

    // CREATE işlemi
    public function create($table, $data, $returnId = false) {
        $columns = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));
        $values = array_values($data);

        $query = "INSERT INTO $table ($columns) VALUES ($placeholders)";

        $this->bindAndExecute($query, $values);

        return $returnId ? $this->db->lastInsertId() : true;
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


/*
<?php
require_once 'DatabaseHandler.php';
require_once 'CRUDHandler.php';

try {
    // DatabaseHandler üzerinden bağlantıyı alın
    $dbConnection = DatabaseHandler::getInstance()->getConnection();
    $crud = new CRUDHandler($dbConnection);

    // 1. Yeni Kayıt Ekleme (CREATE)
    // Yeni bir kullanıcı ekler ve ID'sini döner.
    $newUserId = $crud->create(
        'users',
        [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT),
            'is_verified' => 0,
            'role_id' => 3 // Guest rolü
        ],
        true // Yeni kullanıcı ID'sini döndür
    );
    echo "Yeni kullanıcı ID: $newUserId\n";

    // 2. Tek Bir Kayıt Getirme (READ)
    // Belirtilen e-posta adresine sahip kullanıcıyı getirir.
    $user = $crud->read(
        table: 'users',
        searchs: ['email' => 'john.doe@example.com']
    );
    if ($user) {
        echo "Kullanıcı adı: {$user['name']}, E-posta: {$user['email']}\n";
    } else {
        echo "Kullanıcı bulunamadı.\n";
    }

    // 3. Tüm Kayıtları Getirme
    // `users` tablosundaki tüm kullanıcıları getirir.
    $users = $crud->read(
        table: 'users',
        fetchAll: true
    );
    foreach ($users as $user) {
        echo "ID: {$user['id']}, Ad: {$user['name']}, E-posta: {$user['email']}\n";
    }

    // 4. WHERE ve LIKE Operatörleri Kullanımı
    // İsmi "John" içeren kullanıcıları getirir.
    $usersWithName = $crud->read(
        table: 'users',
        searchs: [
            'name' => ['operator' => 'LIKE', 'value' => '%John%']
        ],
        fetchAll: true
    );
    foreach ($usersWithName as $user) {
        echo "Kullanıcı: {$user['name']} - E-posta: {$user['email']}\n";
    }

    // 5. Pagination Kullanımı
    // 2. sayfadan 5 kayıt getirir.
    $page = 2;
    $itemsPerPage = 5;
    $paginatedUsers = $crud->read(
        table: 'users',
        extras: 'ORDER BY created_at DESC',
        fetchAll: true,
        page: $page,
        itemsPerPage: $itemsPerPage
    );
    foreach ($paginatedUsers as $user) {
        echo "Sayfa 2 Kullanıcı: {$user['name']} - E-posta: {$user['email']}\n";
    }

    // 6. JOIN Kullanımı
    // Kullanıcıların rollerini getirir.
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

    // 7. Güncelleme İşlemi (UPDATE)
    // Kullanıcının adını günceller.
    $updateStatus = $crud->update(
        'users',
        ['name' => 'John Updated'],
        ['email' => 'john.doe@example.com']
    );
    echo $updateStatus ? "Kullanıcı güncellendi.\n" : "Kullanıcı güncellenemedi.\n";

    // 8. Silme İşlemi (DELETE)
    // Belirtilen e-posta adresine sahip kullanıcıyı siler.
    $deleteStatus = $crud->delete(
        'users',
        ['email' => 'john.doe@example.com']
    );
    echo $deleteStatus ? "Kullanıcı silindi.\n" : "Kullanıcı silinemedi.\n";

    // 9. Toplam Kayıt Sayısı (COUNT)
    // Tüm kullanıcıların toplam sayısını döner.
    $totalUsers = $crud->count('users');
    echo "Toplam kullanıcı sayısı: $totalUsers\n";

    // 10. Filtreli Kayıt Sayısı
    // Doğrulanmış kullanıcıların toplam sayısını döner.
    $verifiedUsers = $crud->count(
        table: 'users',
        searchs: ['is_verified' => 1]
    );
    echo "Doğrulanmış kullanıcı sayısı: $verifiedUsers\n";

    // 11. Gruplandırma ve Sıralama
    // Her bir eylemin toplam sayısını getirir.
    $logs = $crud->read(
        table: 'logs',
        columns: ['action', 'COUNT(*) AS total'],
        extras: 'GROUP BY action ORDER BY total DESC',
        fetchAll: true
    );
    foreach ($logs as $log) {
        echo "Eylem: {$log['action']} - Toplam: {$log['total']}\n";
    }

    // 12. Tarihe Göre Filtreleme
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
} catch (Exception $e) {
    echo "Hata: " . $e->getMessage();
}

*/

?>