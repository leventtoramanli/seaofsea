<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;

class CVHandler {
    private $crud;
    private $userId;
    private static $logger;
    private static $loggerInfo;
    private $table = 'user_cvs';

    public function __construct() {
        $this->crud = new CRUDHandler();
        $this->userId = getUserIdFromToken();
        if (!self::$logger) self::$logger = getLogger();
        if (!self::$loggerInfo) self::$loggerInfo = getLoggerInfo();
    }

    private function buildResponse(bool $success, string $message, array $data = [], bool $showMessage = false, array $errors = []): array {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'showMessage' => $showMessage,
        ];
    }

    public function getCV(): array {
        if (!$this->userId) {
            return $this->buildResponse(false, 'Unauthorized');
        }

        $cv = $this->crud->get($this->table, ['user_id' => $this->userId]);
        return $this->buildResponse(true, 'CV alındı', $cv);
    }

    public function createOrUpdateCV(): array {
        if (!$this->userId) {
            return $this->buildResponse(false, 'Unauthorized');
        }

        $fields = $_POST;

        $jsonFields = ['basic_info', 'education', 'experience', 'skills', 'certificates', 'seafarer_info'];
        foreach ($jsonFields as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $fields[$field] = json_decode($fields[$field], true);
            }
        }

        $data = [
            'user_id' => $this->userId,
            'basic_info' => $fields['basic_info'] ?? [],
            'education' => $fields['education'] ?? [],
            'experience' => $fields['experience'] ?? [],
            'skills' => $fields['skills'] ?? [],
            'certificates' => $fields['certificates'] ?? [],
            'seafarer_info' => $fields['seafarer_info'] ?? null,
        ];

        $existing = $this->crud->get($this->table, ['user_id' => $this->userId]);
        if ($existing) {
            $this->crud->update($this->table, $data, ['user_id' => $this->userId]);
            self::$logger->info("CV updated", array_merge(['user_id' => $this->userId], self::$loggerInfo));
            return $this->buildResponse(true, 'CV güncellendi');
        } else {
            $this->crud->create($this->table, $data);
            self::$logger->info("CV created", array_merge(['user_id' => $this->userId], self::$loggerInfo));
            return $this->buildResponse(true, 'CV oluşturuldu');
        }
    }
}
