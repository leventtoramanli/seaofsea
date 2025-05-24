<?php

use Illuminate\Database\Capsule\Manager as Capsule;

class CRUDHandler {
    private static $logger;

    public function __construct() {
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }
    }
    // CREATE
    public function create(string $table, array $data): int|bool {
        return $this->executeQuery(function () use ($table, $data) {
            return DatabaseHandler::getConnection()->table($table)->insertGetId($data);
        }, 'Create operation failed');
    }

    // READ
    public function read(
        string $table,
        array $conditions = [],
        array $columns = ['*'],
        bool $fetchAll = true,
        array $joins = [],
        array $pagination = [],
        array $additionaly = [],
        bool $asArray = false
    ): mixed {
        return $this->executeQuery(function () use ($table, $conditions, $columns, $fetchAll, $joins, $pagination, $additionaly, $asArray) {
            $query = Capsule::table($table)->select($columns);

            foreach ($joins as $join) {
                $type = strtolower($join['type'] ?? 'inner'); // Varsayılan INNER JOIN
                if ($type === 'inner') {
                    $query->join($join['table'], $join['on1'], $join['operator'], $join['on2']);
                } elseif ($type === 'left') {
                    $query->leftJoin($join['table'], $join['on1'], $join['operator'], $join['on2']);
                } else {
                    throw new \Exception("Unsupported join type: {$type}");
                }
            }

            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }

            foreach ($additionaly as $method => $args) {
                // `groupBy` ve `orderBy` özel işleme ihtiyaç duyar
                if ($method === 'groupBy') {
                    $query->groupBy(...$args);
                } elseif ($method === 'orderBy') {
                    foreach ($args as $column => $direction) {
                        $query->orderBy($column, $direction);
                    }
                } elseif (method_exists($query, $method)) {
                    $query->$method(...$args);
                } else {
                    self::$logger->error("Unsupported method detected.", ['method' => $method, 'args' => $args]);
                    throw new \Exception("Unsupported method: {$method}");
                }
            }            

            if (!empty($pagination)) {
                $query->limit($pagination['limit'])->offset($pagination['offset']);
            }
            if ($fetchAll) {
                $result = $query->get();
                return $asArray ? $result->map(fn($r) => (array)$r)->toArray() : $result;
            } else {
                $result = $query->first();
                return $asArray ? (array) $result : $result;
            }            
        }, 'Read operation failed');
    }

    // UPDATE
    public function update(string $table, array $data, array $conditions): int|bool {
        return $this->executeQuery(function () use ($table, $data, $conditions) {
            global $logger;
    
            if (empty($data) || empty($conditions)) {
                $logger->error('🛑 UPDATE HATASI: Eksik parametreler', ['table' => $table, 'data' => $data, 'conditions' => $conditions]);
                return false;
            }
    
            // ✅ Mevcut veriyi çek ve değişiklik olup olmadığını kontrol et
            $existingData = Capsule::table($table)
                ->select(array_keys($data))
                ->where($conditions)
                ->first(); // Tek satır getirir
    
            if ($existingData) {
                $existingArray = (array) $existingData;
                if ($existingArray == $data) {
                    $logger->info('🔍 UPDATE atlandı: Veri zaten güncel.', ['table' => $table, 'existing' => $existingArray, 'new' => $data]);
                    return true; // Hiç `update()` yapmadan başarılı dön
                }
            }
    
            $query = Capsule::table($table);
            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }
    
            $logger->info('🔍 UPDATE İşlemi Başlıyor', [
                'table' => $table,
                'data' => $data,
                'conditions' => $conditions
            ]);
    
            try {
                $result = $query->update($data);
                if ($result) {
                    $logger->info('✅ UPDATE Başarılı!', ['table' => $table, 'data' => $data, 'conditions' => $conditions]);
                    return $result;
                } else {
                    $logger->info('⚠️ UPDATE yapılmadı: Zaten günceldi.', ['table' => $table, 'data' => $data, 'conditions' => $conditions]);
                    return true; // FALSE yerine TRUE dön
                }
            } catch (Exception $e) {
                $logger->error('🛑 UPDATE Hata: ' . $e->getMessage());
                return false;
            }
        }, 'Update operation failed');
    }      

    // DELETE
    public function delete(string $table, array $conditions): int|bool {
        return $this->executeQuery(function () use ($table, $conditions) {
            $query = Capsule::table($table);
            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }
            return $query->delete();
        }, 'Delete operation failed');
    }

    // COUNT
    public function count(string $table, array $conditions = []): int {
        return $this->executeQuery(function () use ($table, $conditions) {
            $query = Capsule::table($table);
            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }
            return $query->count();
        }, 'Count operation failed', 0);
    }

    // ADVANCED QUERY
    public function advancedQuery(string $table, callable $callback): mixed {
        return $this->executeQuery(function () use ($table, $callback) {
            $query = Capsule::table($table);
            return $callback($query);
        }, 'Advanced query failed');
    }

    // Common Query Executor
    private function executeQuery(callable $callback, string $errorMessage, $default = false): mixed {
        try {
            return $callback();
        } catch (\Exception $e) {
            self::$logger->error($errorMessage, ['exception' => $e]);
            return $default;
        }
    }

    private function applyCondition($query, string $key, $value): void {
        if (is_array($value)) {
            $operator = strtoupper($value[0]);
            $operand = $value[1];
    
            if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
                $query->where($key, $operator, $operand);
            } elseif ($operator === 'IN') {
                $query->whereIn($key, (array) $operand);
            } elseif ($operator === 'NOT IN') {
                $query->whereNotIn($key, (array) $operand);
            } else {
                // Herhangi başka bir operatör ise (=, !=, <, > gibi)
                $query->where($key, $operator, $operand);
            }
        } elseif (is_null($value)) {
            $query->whereNull($key);
        } else {
            $query->where($key, '=', $value);
        }
    }
    
    // Apply Condition Helper
    /*private function applyCondition($query, string $key, $value): void {
        if (is_array($value)) {
            $operator = $value['operator'] ?? '=';
            $query->where($key, $operator, $value['value']);
        } else {
            $query->where($key, $value);
        }
    }*/
    public function deleteExpiredRefreshTokens() {
        try {
            $deletedCount = Capsule::table('refresh_tokens')
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->delete();
    
            return $deletedCount; // Silinen kayıtların sayısını döndür
        } catch (Exception $e) {
            self::$logger->error('Failed to delete expired refresh tokens.', ['exception' => $e]);
            throw $e;
        }
    }
}
