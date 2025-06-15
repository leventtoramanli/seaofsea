<?php

class Crud
{
    private $pdo;
    private $logger;
    private $userId;
    private array $tableColumnsCache = [];

    private array $disallowedTables = [
        'migrations',
        'logs',
        'sensitive_data',
        'admin_only_stuff',
    ];

    public function __construct(int $userId = null)
    {
        $this->pdo = DB::getInstance();
        $this->logger = Logger::getInstance();
        $this->userId = $userId;
    }

    private function isAllowedTable(string $table): bool
    {
        return !in_array($table, $this->disallowedTables);
    }


    private function getTableColumns(string $table): array
    {
        if (isset($this->tableColumnsCache[$table])) {
            return $this->tableColumnsCache[$table];
        }

        try {
            $stmt = $this->pdo->query("DESCRIBE `$table`");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->tableColumnsCache[$table] = $columns;
            return $columns;
        } catch (PDOException $e) {
            $this->logger->error("Failed to fetch columns for $table", ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function isValidColumn(string $table, string $column): bool
    {
        if (in_array($table, $this->disallowedTables)) {
            return false;
        }
        $columns = $this->getTableColumns($table);
        return in_array($column, $columns);
    }

    private function isPublicAccess(string $table, string $action): bool
    {
        if (!$this->isAllowedTable($table)) {
            return false;
        }
        $sql = "SELECT allowed_actions, user_ids, is_enabled, is_restricted FROM public_access_rules WHERE table_name = :table LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table' => $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['is_enabled']) {
            return false;
        }

        $actions = json_decode($row['allowed_actions'], true) ?? [];
        if (!in_array($action, $actions)) {
            return false;
        }

        if ($row['is_restricted']) {
            $allowedUsers = json_decode($row['user_ids'], true);
            return is_array($allowedUsers) && in_array($this->userId, $allowedUsers);
        }

        return true;
    }

    private function hasPermission(string $action, string $table): bool
    {
        if (!$this->isAllowedTable($table)) {
            return false;
        }

        if (!$this->userId && $this->isPublicAccess($table, $action)) {
            return true;
        }

        if (!class_exists('AuthService') || !method_exists('AuthService', 'can')) {
            $this->logger->error("AuthService::can method not found");
            return false;
        }

        $sql = "SELECT COUNT(*) FROM permissions WHERE code = :code";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['code' => "$table.$action"]);
        $count = (int) $stmt->fetchColumn();

        if ($count === 0) {
            return true;
        }

        return AuthService::can($this->userId, $action, $table);
    }
    public function create(string $table, array $data): int|false
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('create', $table)) {
            return false;
        }

        foreach (array_keys($data) as $col) {
            if (!$this->isValidColumn($table, $col)) {
                return false;
            }
        }

        $columnsStr = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn ($k) => ':' . $k, array_keys($data)));
        $sql = "INSERT INTO `$table` ($columnsStr) VALUES ($placeholders)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->logger->error("CREATE failed in $table", ['error' => 'DB error']);
            return false;
        }
    }

    public function read(string $table, array $conditions = [], bool $fetchAll = true): mixed
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('read', $table)) {
            return false;
        }

        $sql = "SELECT * FROM `$table`";
        if (!empty($conditions)) {
            foreach (array_keys($conditions) as $col) {
                if (!$this->isValidColumn($table, $col)) {
                    return false;
                }
            }
            $sql .= " WHERE " . implode(' AND ', array_map(fn ($k) => "$k = :$k", array_keys($conditions)));
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($conditions);
            return $fetchAll ? $stmt->fetchAll(PDO::FETCH_ASSOC) : $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error("READ failed in $table", ['error' => 'DB error']);
            return false;
        }
    }

    public function update(string $table, array $data, array $conditions): bool
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('update', $table)) {
            return false;
        }

        foreach (array_merge(array_keys($data), array_keys($conditions)) as $col) {
            if (!$this->isValidColumn($table, $col)) {
                return false;
            }
        }

        $setClause = implode(', ', array_map(fn ($k) => "$k = :set_$k", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn ($k) => "$k = :where_$k", array_keys($conditions)));

        $sql = "UPDATE `$table` SET $setClause WHERE $whereClause";
        $params = [];
        foreach ($data as $k => $v) {
            $params["set_$k"] = $v;
        }
        foreach ($conditions as $k => $v) {
            $params["where_$k"] = $v;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->logger->error("UPDATE failed in $table", ['error' => 'DB error']);
            return false;
        }
    }

    public function delete(string $table, array $conditions): bool
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('delete', $table)) {
            return false;
        }

        foreach (array_keys($conditions) as $col) {
            if (!$this->isValidColumn($table, $col)) {
                return false;
            }
        }

        $whereClause = implode(' AND ', array_map(fn ($k) => "$k = :$k", array_keys($conditions)));
        $sql = "DELETE FROM `$table` WHERE $whereClause";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($conditions);
        } catch (PDOException $e) {
            $this->logger->error("DELETE failed in $table", ['error' => 'DB error']);
            return false;
        }
    }

    public function advancedRead(string $table, array $filters = [], array $sort = [], int $limit = 100, int $offset = 0): array|false
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('advancedRead', $table)) {
            return false;
        }

        $where = [];
        $params = [];

        foreach ($filters as $f) {
            $field = $f['field'];
            $operator = strtoupper($f['operator']);
            $value = $f['value'];

            if (!$this->isValidColumn($table, $field)) {
                return false;
            }

            switch ($operator) {
                case 'BETWEEN':
                    $where[] = "$field BETWEEN :{$field}_from AND :{$field}_to";
                    $params["{$field}_from"] = $value[0];
                    $params["{$field}_to"] = $value[1];
                    break;
                case 'IS NULL':
                    $where[] = "$field IS NULL";
                    break;
                default:
                    $where[] = "$field = :$field";
                    $params[$field] = $value;
            }
        }

        $sql = "SELECT * FROM `$table`";
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        if (!empty($sort)) {
            $orderParts = [];
            foreach ($sort as $s) {
                $col = $s['column'];
                if (!$this->isValidColumn($table, $col)) {
                    return false;
                }
                $dir = strtoupper($s['direction']) === 'DESC' ? 'DESC' : 'ASC';
                $orderParts[] = "$col $dir";
            }
            $sql .= " ORDER BY " . implode(", ", $orderParts);
        }

        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => &$v) {
                $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindParam(":" . $k, $v, $type);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error("ADVANCED READ failed in $table", ['error' => 'DB error']);
            return false;
        }
    }

    public function query(string $sql, array $params = []): array|false
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error("RAW QUERY ERROR", ['error' => 'DB error']);
            return false;
        }
    }
}
