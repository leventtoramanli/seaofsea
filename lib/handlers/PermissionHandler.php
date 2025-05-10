<?php
require_once __DIR__ . '/../handlers/UserHandler.php';
require_once __DIR__ . '/../handlers/CRUDHandlers.php';
require_once __DIR__ . '/../handlers/PermissionHelper.php';
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
class PermissionHandler {
    private $crudHandler;
    private $userId;
    private $userRoleId;

    public function __construct() {
        $this->crudHandler = new CRUDHandler();
        $userId = getUserIdFromToken();
        if (!$userId) {
            http_response_code(401);
            jsonResponse(false, 'Unauthorized');
        }
        $this->userId = $userId;
        $user = $this->crudHandler->read('users', ['id' => $userId], ['role_id'], false);
        if (is_object($user)) {
            $user = (array) $user;
        }        
        $this->userRoleId = $user['role_id'] ?? null;
    }

    public function checkPermission(string $permissionCode, ?int $entityId = null, string $entityType = 'company'): bool {
        $userId = $this->userId;

        // 1. Kullanıcıya özel izin (user_permissions)
        $userCond = [
            ['user_id', '=', $userId],
            ['permission_code', '=', $permissionCode],
            ['expires_at', 'IS', null]
        ];
        if ($entityId !== null) {
            $userCond[] = [$entityType . '_id', '=', $entityId];
        }
        if ($this->userRoleId == 1) {return true;}

        $userPerm = $this->crudHandler->read('user_permissions', $userCond, ['id'], false);
        if ($userPerm) return true;

        // 2. Kullanıcının rolü ile gelen izin (role_permissions)
        $role = $this->getUserEntityRole($entityId, $entityType);
        if ($role) {
            $rolePerm = $this->crudHandler->read('role_permissions', [
                'role' => $role,
                'permission_code' => $permissionCode
            ], ['id'], false);
            if ($rolePerm) return true;
        }

        // 3. permission.access_level kontrolü (permissions tablosundan)
        $permissionMeta = $this->crudHandler->read('permissions', [
            'code' => $permissionCode
        ], ['access_level'], false);

        if (!empty($permissionMeta['access_level'])) {
            $context = [
                'entity' => $entityType,
                'entity_id' => $entityId,
                'created_by_table' => $entityType . 's',
                'membership_table' => $entityType . '_users',
                'follower_table' => $entityType . '_followers',
                'role_column' => 'role'
            ];
            return PermissionHelper::checkVisibilityScope($permissionMeta['access_level'], $userId, $context);
        }

        return false;
    }

    public function getUserEntityRole(?int $entityId, string $entityType): ?string {
        if (!$entityId) return null;

        $record = $this->crudHandler->read($entityType . '_users', [
            'user_id' => $this->userId,
            $entityType . '_id' => $entityId
        ], ['role'], false);

        return $record['role'] ?? null;
    }

    public function hasPermission(string $permissionCode, ?int $entityId = null, string $entityType = 'company'): bool {
        try {
            return $this->checkPermission($permissionCode, $entityId, $entityType);
        } catch (Exception $e) {
            return false;
        }
    }
    public function getAllPermissions(string $scope = 'company'): array {
        $permissions = $this->crudHandler->read('permissions', [
            'scope' => $scope
        ], ['code', 'description'], true);
    
        if ($permissions instanceof \Illuminate\Support\Collection) {
            $permissions = $permissions->toArray();
        }
    
        return array_map(function ($item) {
            return is_array($item)
                ? ['code' => $item['code'], 'description' => $item['description'] ?? $item['code']]
                : ['code' => $item->code, 'description' => $item->description ?? $item->code];
        }, $permissions);
    }      

    public function getAllUserPermissions(?int $entityId = null, string $entityType = 'company'): array {
        $userId = $this->userId;
    
        // ✅ Admin'e tüm izinleri ver
        if ($this->userRoleId == 1) {
            $all = $this->crudHandler->read('permissions', [], ['code'], true);
    
            if ($all instanceof \Illuminate\Support\Collection) {
                $all = $all->toArray();
            }
    
            $all = array_map(function ($item) {
                return is_array($item) ? $item['code'] : $item->code;
            }, $all);
    
            return $all;
        }
        $permissions = [];
        $userPerms = $this->crudHandler->read('user_permissions', [
            'user_id' => $userId,
            [$entityType . '_id', '=', $entityId ?? 0],
            ['expires_at', 'IS', null]
        ], ['permission_code'], true);
    
        if ($userPerms instanceof \Illuminate\Support\Collection) {
            $userPerms = $userPerms->toArray();
        }
    
        foreach ($userPerms as $p) {
            $permissions[] = is_array($p) ? $p['permission_code'] : $p->permission_code;
        }
        $role = $this->getUserEntityRole($entityId, $entityType);
        if ($role) {
            $rolePerms = $this->crudHandler->read('role_permissions', [
                'role' => $role
            ], ['permission_code'], true);
    
            if ($rolePerms instanceof \Illuminate\Support\Collection) {
                $rolePerms = $rolePerms->toArray();
            }
    
            foreach ($rolePerms as $p) {
                $permissions[] = is_array($p) ? $p['permission_code'] : $p->permission_code;
            }
        }
        return array_values(array_unique($permissions));
    }    
    public function updateUserPermissions(int $targetUserId, int $companyId, array $permissionCodes): bool {
        // Eski izinleri sil
        $this->crudHandler->delete('user_permissions', [
            'user_id' => $targetUserId,
            'company_id' => $companyId
        ]);
    
        // Yeni izinleri ekle
        foreach ($permissionCodes as $code) {
            $this->crudHandler->create('user_permissions', [
                'user_id' => $targetUserId,
                'company_id' => $companyId,
                'permission_code' => $code,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    
        return true;
    }    
}