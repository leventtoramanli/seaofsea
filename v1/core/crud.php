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

    /** PermissionService gibi yerlerde RBAC’i bypass etmek için küçük guard */
    private bool $permissionGuard = true;

    // ⬇⬇⬇  IMZAYI DÜZELTTİK: ikinci parametre eklendi
    public function __construct(?int $userId = null, bool $permissionGuard = true)
    {
        $this->pdo = DB::getInstance();
        $this->logger = Logger::getInstance();
        $this->userId = $userId;
        $this->permissionGuard = $permissionGuard; // tanımsız değişken hatası gider
    }

    private function isAllowedTable(string $table, ?string $action = null): bool
    {
        $hardBlocked = ['migrations', 'logs', 'sensitive_data'];
        if (in_array($table, $hardBlocked, true)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM public_access_rules 
                WHERE table_name = :table AND is_enabled = 1
                LIMIT 1
            ");
            $stmt->execute(['table' => $table]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                return true;
            }

            if ((int)$rule['is_restricted'] === 1) {
                if (!$this->userId) {
                    return false;
                }
                $userIds = json_decode($rule['user_ids'] ?? '[]', true) ?: [];
                if (!in_array($this->userId, $userIds, true)) {
                    return false;
                }
            }

            if (!empty($action) && !empty($rule['allowed_actions'])) {
                $allowedActions = array_map('trim', explode(',', strtolower((string)$rule['allowed_actions'])));
                if (!in_array(strtolower($action), $allowedActions, true)) {
                    return false;
                }
            }

            return true;
        } catch (PDOException $e) {
            Logger::error("ACCESS_CHECK failed", [
                'table' => $table,
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
            $this->logger->error("COLUMN_FETCH failed for $table", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    private function isValidColumn(string $table, string $column): bool
    {
        // Şimdilik davranışı bozmayalım:
        return true;

        // Güvenliği sıkmak istediğinde yukarıdaki 'return true' satırını kaldır,
        // ve aşağıyı aktif et.
        /*
        if (in_array($table, $this->disallowedTables, true)) {
            return false;
        }
        $columns = $this->getTableColumns($table);
        return in_array($column, $columns, true);
        */
    }

    private function isPublicAccess(string $table, string $action): bool
    {
        if (!$this->isAllowedTable($table, $action)) {
            return false;
        }

        $sql = "SELECT allowed_actions, user_ids, is_enabled, is_restricted 
                FROM public_access_rules 
                WHERE table_name = :table 
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table' => $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['is_enabled'] !== 1) {
            return false;
        }

        $allowed = [];
        if ($row['allowed_actions'] !== null) {
            $decoded = json_decode($row['allowed_actions'], true);
            if (is_array($decoded)) {
                $allowed = array_map('strtolower', $decoded);
            } else {
                $allowed = array_map('strtolower', array_map('trim', explode(',', (string)$row['allowed_actions'])));
            }
        }

        if ($allowed && !in_array(strtolower($action), $allowed, true)) {
            return false;
        }

        if ((int)$row['is_restricted'] === 1) {
            if (!$this->userId) return false;
            $allowedUsers = json_decode($row['user_ids'] ?? '[]', true) ?: [];
            return in_array($this->userId, $allowedUsers, true);
        }

        return true;
    }

    private function hasPermission(string $action, string $table): bool
    {
        // PermissionService içinde guard'ı kapatacağız
        if ($this->permissionGuard === false) return true;

        if (!$this->isAllowedTable($table)) {
            return false;
        }

        // Public access kuralı varsa ve anonim istekse izin ver
        if (!$this->userId && $this->isPublicAccess($table, $action)) {
            return true;
        }

        if (!class_exists('AuthService') || !method_exists('AuthService', 'can')) {
            Logger::error("AuthService::can method not found", [
                'user_id' => $this->userId,
                'action' => $action,
                'table' => $table
            ]);
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM permissions WHERE code = :code");
        $stmt->execute(['code' => "{$table}.{$action}"]);
        $count = (int)$stmt->fetchColumn();

        if ($count === 0) {
            // izin tanımlı değilse default allow (mevcut davranışı bozmayalım)
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

        $columnsStr = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $placeholders = implode(', ', array_map(fn ($k) => ':' . $k, array_keys($data)));
        $sql = "INSERT INTO `$table` ($columnsStr) VALUES ($placeholders)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            Logger::error("CREATE failed in $table", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private static function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    // (opsiyonel) gerek yoksa bu helper'ı kaldırabilirsin
    function is_assoc(array $arr): bool {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function count(string $table, array $conditions = []): int|false
    {
        // read ile aynı güvenlik/erişim sözleşmesi
        if (!$this->isAllowedTable($table) || !$this->hasPermission('read', $table)) {
            return false;
        }

        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}`";
        $whereParts = [];
        $params = [];
        $idx = 0;

        foreach ($conditions as $col => $val) {
            if (!$this->isValidColumn($table, $col)) {
                return false;
            }

            if (!is_array($val)) {
                $ph = ":{$col}";
                $whereParts[] = "`{$col}` = {$ph}";
                $params[$col] = $val;
                continue;
            }

            $op = strtoupper((string)($val[0] ?? ''));
            switch ($op) {
                case 'IN':
                case 'NOT IN':
                    $list = (array)($val[1] ?? []);
                    if (empty($list)) {
                        $whereParts[] = ($op === 'IN') ? '1=0' : '1=1';
                        break;
                    }
                    $phs = [];
                    foreach ($list as $item) {
                        $phName = "{$col}_{$idx}";
                        $phs[] = ":{$phName}";
                        $params[$phName] = $item;
                        $idx++;
                    }
                    $whereParts[] = "`{$col}` {$op} (" . implode(',', $phs) . ")";
                    break;

                case 'LIKE':
                    $ph = ":{$col}_like_{$idx}";
                    $whereParts[] = "`{$col}` LIKE {$ph}";
                    $params["{$col}_like_{$idx}"] = $val[1] ?? '';
                    $idx++;
                    break;

                case 'BETWEEN':
                    $range = (array)($val[1] ?? []);
                    $fromPh = ":{$col}_from_{$idx}";
                    $toPh   = ":{$col}_to_{$idx}";
                    $whereParts[] = "`{$col}` BETWEEN {$fromPh} AND {$toPh}";
                    $params["{$col}_from_{$idx}"] = $range[0] ?? null;
                    $params["{$col}_to_{$idx}"]   = $range[1] ?? null;
                    $idx++;
                    break;

                case 'IS NULL':
                case 'IS NOT NULL':
                    $whereParts[] = "`{$col}` {$op}";
                    break;

                case '>':
                case '>=':
                case '<':
                case '<=':
                case '!=':
                case '<>':
                case '=':
                    $ph = ":{$col}_cmp_{$idx}";
                    $whereParts[] = "`{$col}` {$op} {$ph}";
                    $params["{$col}_cmp_{$idx}"] = $val[1] ?? null;
                    $idx++;
                    break;

                default:
                    $ph = ":{$col}_eq_{$idx}";
                    $whereParts[] = "`{$col}` = {$ph}";
                    $params["{$col}_eq_{$idx}"] = $val[1] ?? null;
                    $idx++;
                    break;
            }
        }

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':' . $k, $v, $type);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0);
        } catch (PDOException $e) {
            Logger::error("COUNT failed in {$table}", [
                'sql'   => $sql,
                'params'=> $params,
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function read(
        string $table,
        array $conditions = [],
        array|bool $columnsOrFetchAll = ['*'],
        bool $fetchAll = true,
        array $orderBy = [],
        array $groupBy = [],
        array $options = []
    ): mixed 
    {
        if (!$this->isAllowedTable($table) || !$this->hasPermission('read', $table)) {
            return false;
        }

        $columns = ['*'];
        if (is_bool($columnsOrFetchAll)) {
            $fetchAll = $columnsOrFetchAll;
        } elseif (is_array($columnsOrFetchAll) && !empty($columnsOrFetchAll)) {
            $columns = $columnsOrFetchAll;
        }
        $select = implode(', ', $columns);

        $sql = "SELECT {$select} FROM `{$table}`";

        $whereParts = [];
        $params = [];
        $idx = 0;

        foreach ($conditions as $col => $val) {
            if (!$this->isValidColumn($table, $col)) {
                return false;
            }

            if (!is_array($val)) {
                $ph = ":{$col}";
                $whereParts[] = "`{$col}` = {$ph}";
                $params[$col] = $val;
                continue;
            }

            $op = strtoupper((string)($val[0] ?? ''));
            switch ($op) {
                case 'IN':
                case 'NOT IN': {
                    // Giriş şekli -> [$col, ['IN', $list]]
                    $list = (array)($val[1] ?? []);
                    // Null/duplikasyon temizliği + basit tip normalize
                    $list = array_values(array_unique(array_filter($list, static fn($v) => $v !== null)));

                    if (empty($list)) {
                        // Boş liste olayı -> IN -> her zaman false; NOT IN -> her zaman true
                        $whereParts[] = ($op === 'IN') ? '1=0' : '1=1';
                        break;
                    }

                    $phs = [];
                    foreach ($list as $item) {
                        // Parametre adlarının çakışmaması için ++
                        $phName = "{$col}_" . ($idx++);
                        $phs[] = ":{$phName}";
                        // Sayısal olanları integer yada float yap, diğerlerini string olarak bırak
                        if (is_numeric($item)) {
                            $params[$phName] = (strpos((string)$item, '.') !== false) ? (float)$item : (int)$item;
                        } else {
                            $params[$phName] = (string)$item;
                        }
                    }

                    $whereParts[] = "`{$col}` {$op} (" . implode(',', $phs) . ")";
                    break;
                }

                case 'LIKE':
                    $ph = ":{$col}_like_{$idx}";
                    $whereParts[] = "`{$col}` LIKE {$ph}";
                    $params["{$col}_like_{$idx}"] = $val[1] ?? '';
                    $idx++;
                    break;

                case 'BETWEEN':
                    $range = (array)($val[1] ?? []);
                    $fromPh = ":{$col}_from_{$idx}";
                    $toPh   = ":{$col}_to_{$idx}";
                    $whereParts[] = "`{$col}` BETWEEN {$fromPh} AND {$toPh}";
                    $params["{$col}_from_{$idx}"] = $range[0] ?? null;
                    $params["{$col}_to_{$idx}"]   = $range[1] ?? null;
                    $idx++;
                    break;

                case 'IS NULL':
                case 'IS NOT NULL':
                    $whereParts[] = "`{$col}` {$op}";
                    break;

                case '>':
                case '>=':
                case '<':
                case '<=':
                case '!=':
                case '<>':
                case '=':
                    $ph = ":{$col}_cmp_{$idx}";
                    $whereParts[] = "`{$col}` {$op} {$ph}";
                    $params["{$col}_cmp_{$idx}"] = $val[1] ?? null;
                    $idx++;
                    break;

                default:
                    $ph = ":{$col}_eq_{$idx}";
                    $whereParts[] = "`{$col}` = {$ph}";
                    $params["{$col}_eq_{$idx}"] = $val[1] ?? null;
                    $idx++;
                    break;
            }
        }

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        if (!empty($groupBy)) {
            $isAssoc = array_keys($groupBy) !== range(0, count($groupBy) - 1);
            $groupCols = $isAssoc ? array_keys($groupBy) : array_values($groupBy);
            foreach ($groupCols as $gc) {
                if (!$this->isValidColumn($table, $gc)) {
                    return false;
                }
            }
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($c) => "`{$c}`", $groupCols));
        }

        if (!empty($orderBy)) {
            $parts = [];
            $isAssoc = array_keys($orderBy) !== range(0, count($orderBy) - 1);
            if ($isAssoc) {
                foreach ($orderBy as $col => $dir) {
                    $dir = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
                    $parts[] = "`{$col}` {$dir}";
                }
            } else {
                foreach ($orderBy as $o) {
                    $col = $o['column'] ?? null;
                    if (!$col || !$this->isValidColumn($table, $col)) continue;
                    $dir = strtoupper((string)($o['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
                    $parts[] = "`{$col}` {$dir}";
                }
            }
            if ($parts) {
                $sql .= ' ORDER BY ' . implode(', ', $parts);
            }
        }

        $limit  = $options['limit']  ?? null;
        $offset = $options['offset'] ?? null;
        if ($limit !== null) {
            $sql .= ' LIMIT :__limit';
            $params['__limit'] = (int)$limit;
            if ($offset !== null) {
                $sql .= ' OFFSET :__offset';
                $params['__offset'] = (int)$offset;
            }
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':' . $k, $v, $type);
            }

            $stmt->execute();
            return $fetchAll ? $stmt->fetchAll(PDO::FETCH_ASSOC)
                             : $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            Logger::error("READ failed in {$table}", [
                'sql'   => $sql,
                'params'=> $params,
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function update(string $table, array $data, array $conditions): bool
    {
        if (!$this->isAllowedTable($table, 'update') || !$this->hasPermission('update', $table)) {
            Logger::error("Update blocked by access control", ['table' => $table, 'user_id' => $this->userId]);
            return false;
        }

        foreach (array_merge(array_keys($data), array_keys($conditions)) as $col) {
            if (!$this->isValidColumn($table, $col)) {
                Logger::error("Invalid column in update", ['column' => $col, 'table' => $table]);
                return false;
            }
        }

        $setClause = implode(', ', array_map(fn ($k) => "`$k` = :set_$k", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn ($k) => "`$k` = :where_$k", array_keys($conditions)));

        $sql = "UPDATE `$table` SET $setClause WHERE $whereClause";
        $params = [];
        foreach ($data as $k => $v) { $params["set_$k"] = $v; }
        foreach ($conditions as $k => $v) { $params["where_$k"] = $v; }

        try {
            $stmt = $this->pdo->prepare($sql);
            $executed = $stmt->execute($params);

            // idempotent dönelim (0 row affected olsa da true)
            return (bool)$executed;
        } catch (PDOException $e) {
            Logger::error("❌ UPDATE failed in $table", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
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

        $whereClause = implode(' AND ', array_map(fn ($k) => "`$k` = :$k", array_keys($conditions)));
        $sql = "DELETE FROM `$table` WHERE $whereClause";

        try {
            $stmt = $this->pdo->prepare($sql);
            return (bool)$stmt->execute($conditions);
        } catch (PDOException $e) {
            Logger::error("DELETE failed in $table", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
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
            Logger::error("ADVANCED_READ failed in $table", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
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
            Logger::error("RAW_QUERY failed", [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
