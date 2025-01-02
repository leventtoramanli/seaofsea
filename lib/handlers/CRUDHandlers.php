<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CRUDHandler {
    private static $logger;

    public function __construct() {
        if (!self::$logger) {
            self::$logger = new Logger('database');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/database.log', Logger::ERROR));
        }
    }

    // CREATE işlemi
    public function create(string $table, array $data): int|bool {
        try {
            $insertId = Capsule::table($table)->insertGetId($data);
            return $insertId;
        } catch (\Exception $e) {
            $this->logError($e, 'Create operation failed');
            return false;
        }
    }

    // READ işlemi
    public function read(
        string $table,
        array $conditions = [],
        array $columns = ['*'],
        bool $fetchAll = true,
        array $joins = [],
        array $pagination = [],
        array $additionaly = []
    ): mixed {
        try {
            $query = Capsule::table($table)->select($columns);

            // JOIN ekle
            foreach ($joins as $join) {
                $type = $join['type'] ?? 'inner'; // Varsayılan JOIN türü
                $query->$type($join['table'], $join['on1'], $join['operator'], $join['on2']);
            }

            // WHERE koşulları ekle
            foreach ($conditions as $key => $value) {
                if (is_array($value)) {
                    $operator = $value['operator'] ?? '=';
                    $query->where($key, $operator, $value['value']);
                } else {
                    $query->where($key, $value);
                }
            }

            // Extra ekle
            foreach ($additionaly as $method => $args) {
                if (method_exists($query, $method)) {
                    $query->$method(...$args);
                }
            }

            // Pagination ekle
            if (!empty($pagination)) {
                $query->limit($pagination['limit'])->offset($pagination['offset']);
            }

            return $fetchAll ? $query->get() : $query->first();
        } catch (\Exception $e) {
            $this->logError($e, 'Read operation failed');
            return false;
        }
    }

    // UPDATE işlemi
    public function update(string $table, array $data, array $conditions): int|bool {
        try {
            $query = Capsule::table($table);

            foreach ($conditions as $key => $value) {
                $query->where($key, $value);
            }

            return $query->update($data);
        } catch (\Exception $e) {
            $this->logError($e, 'Update operation failed');
            return false;
        }
    }

    // DELETE işlemi
    public function delete(string $table, array $conditions): int|bool {
        try {
            $query = Capsule::table($table);

            foreach ($conditions as $key => $value) {
                $query->where($key, $value);
            }

            return $query->delete();
        } catch (\Exception $e) {
            $this->logError($e, 'Delete operation failed');
            return false;
        }
    }

    // COUNT işlemi
    public function count(string $table, array $conditions = []): int {
        try {
            $query = Capsule::table($table);

            foreach ($conditions as $key => $value) {
                $query->where($key, $value);
            }

            return $query->count();
        } catch (\Exception $e) {
            $this->logError($e, 'Count operation failed');
            return 0;
        }
    }

    // Advanced Query
    public function advancedQuery(string $table, callable $callback): mixed {
        try {
            $query = Capsule::table($table);
            return $callback($query);
        } catch (\Exception $e) {
            $this->logError($e, 'Advanced query failed');
            return false;
        }
    }

    // Hata loglama
    private function logError($exception, $message) {
        self::$logger->error($message, ['exception' => $exception]);
    }
}
