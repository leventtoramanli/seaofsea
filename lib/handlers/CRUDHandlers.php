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
        array $additionaly = []
    ): mixed {
        return $this->executeQuery(function () use ($table, $conditions, $columns, $fetchAll, $joins, $pagination, $additionaly) {
            $query = Capsule::table($table)->select($columns);

            foreach ($joins as $join) {
                $type = $join['type'] ?? 'inner';
                $query->$type($join['table'], $join['on1'], $join['operator'], $join['on2']);
            }

            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }

            foreach ($additionaly as $method => $args) {
                if (method_exists($query, $method)) {
                    $query->$method(...$args);
                }
            }

            if (!empty($pagination)) {
                $query->limit($pagination['limit'])->offset($pagination['offset']);
            }

            return $fetchAll ? $query->get() : $query->first();
        }, 'Read operation failed');
    }

    // UPDATE
    public function update(string $table, array $data, array $conditions): int|bool {
        return $this->executeQuery(function () use ($table, $data, $conditions) {
            $query = Capsule::table($table);
            foreach ($conditions as $key => $value) {
                $this->applyCondition($query, $key, $value);
            }
            return $query->update($data);
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

    // Apply Condition Helper
    private function applyCondition($query, string $key, $value): void {
        if (is_array($value)) {
            $operator = $value['operator'] ?? '=';
            $query->where($key, $operator, $value['value']);
        } else {
            $query->where($key, $value);
        }
    }
}
