<?php
require_once __DIR__ . '/PermissionHandler.php';
require_once __DIR__ . '/../handlers/CRUDHandlers.php';
require_once __DIR__ . '/../handlers/UserHandler.php';

use Illuminate\Database\Capsule\Manager as Capsule;

class CVHandler {
    private $crud;
    private static $logger;
    private static $loggerInfo;
    private ?PermissionHandler $permissionHandler = null;
    private string $table = 'user_cv';

    public function __construct() {
        $this->crud = new CRUDHandler();
        if (!self::$logger) self::$logger = getLogger();
        if (!self::$loggerInfo) self::$loggerInfo = getLoggerInfo();
    }

    private function getUserId(): ?int {
        try {
            return getUserIdFromToken();
        } catch (Exception $e) {
            return null;
        }
    }

    private function getPermissionHandler(): PermissionHandler {
        if (!$this->permissionHandler) {
            $this->permissionHandler = new PermissionHandler();
        }
        return $this->permissionHandler;
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
        $userId = $this->getUserId();
        if (!$userId) {
            return $this->buildResponse(false, 'Unauthorized');
        }

        $cv = $this->crud->read($this->table, ['user_id' => $userId], ['*'], false);
        if (!$cv) {
            return $this->buildResponse(false, 'CV not found');
        }

        return $this->buildResponse(true, 'CV retrieved', $cv);
    }
    public function getCVByUserId($targetUserId): array {
        $userId = $this->getUserId();
        if (!$targetUserId || !is_numeric($targetUserId)) {
            return $this->buildResponse(false, 'Invalid user ID');
        }
    
        $cv = $this->crud->read($this->table, ['user_id' => $targetUserId], ['*'], false);
    
        $isSelf = $userId === (int) $targetUserId;
    
        if (!$cv) {
            return $this->buildResponse(true, 'CV not found', [
                'user_id' => $targetUserId,
                'own' => $isSelf
            ]);
        }
    
        if (is_object($cv)) {
            $cv = (array) $cv;
        }
    
        $scope = $cv['access_scope'] ?? 'all';
    
        if ($scope === 'own' && !$isSelf) {
            return $this->buildResponse(false, 'You are not allowed to view this CV.');
        }
    
        // 🔄 own bilgisini ekle
        $cv['own'] = $isSelf;
        return $this->buildResponse(true, 'CV found', $cv);
    }
    

    public function createOrUpdateCV(): array {
        $userId = $this->getUserId();
        if (!$userId) {
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
            'user_id' => $userId,
            'basic_info' => $fields['basic_info'] ?? [],
            'education' => $fields['education'] ?? [],
            'experience' => $fields['experience'] ?? [],
            'skills' => $fields['skills'] ?? [],
            'certificates' => $fields['certificates'] ?? [],
            'seafarer_info' => $fields['seafarer_info'] ?? null,
        ];

        $existing = $this->crud->read($this->table, ['user_id' => $userId], ['*'], false);
        if ($existing) {
            $this->crud->update($this->table, $data, ['user_id' => $userId]);
            self::$logger->info("CV updated", array_merge(['user_id' => $userId], self::$loggerInfo));
            return $this->buildResponse(true, 'CV updated');
        } else {
            $this->crud->create($this->table, $data);
            self::$logger->info("CV created", array_merge(['user_id' => $userId], self::$loggerInfo));
            return $this->buildResponse(true, 'CV created');
        }
    }
}
