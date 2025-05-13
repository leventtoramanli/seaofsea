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
    public function checkPermission(string $permissionCode, ?int $entityId = null, string $entityType = 'company'): array {
        $userId = $this->userId;

        if ($this->userRoleId == 1) {
            return [
                'success' => true,
                'message' => 'Admin override: permission granted.'
            ];
        }

        // 1. JSON üzerinden kullanıcı özel izni kontrolü (users.sPermission)
        $user = $this->crudHandler->read('users', ['id' => $userId], ['sPermission'], false);
        if ($user && !empty($user['sPermission'])) {
            $json = json_decode($user['sPermission'], true);
            if (is_array($json) && isset($json[$entityId])) {
                $permIds = $json[$entityId];
                $permMeta = $this->crudHandler->read('permissions', [
                    'code' => $permissionCode
                ], ['id'], false);
                if ($permMeta && in_array($permMeta['id'], $permIds)) {
                    return [
                        'success' => true,
                        'message' => 'Permission granted via JSON override.'
                    ];
                }
            }
        }

        // 2. Kullanıcıya özel izin (user_permissions - yalnızca destekleyici)
        $userCond = [
            ['user_id', '=', $userId],
            ['permission_code', '=', $permissionCode],
            ['expires_at', 'IS', null]
        ];
        if ($entityId !== null) {
            $userCond[] = [$entityType . '_id', '=', $entityId];
        }

        $userPerm = $this->crudHandler->read('user_permissions', $userCond, ['id'], false);
        if ($userPerm) {
            return [
                'success' => true,
                'message' => 'Permission granted by user_permissions.'
            ];
        }

        // 3. Rol tabanlı izin (role_permissions)
        $role = $this->getUserEntityRole($entityId, $entityType);
        if ($role) {
            $rolePerm = $this->crudHandler->read('role_permissions', [
                'role' => $role,
                'permission_code' => $permissionCode
            ], ['id'], false);
            if ($rolePerm) {
                return [
                    'success' => true,
                    'message' => 'Permission granted by role.'
                ];
            }
        }

        // 4. Genel erişim düzeyi kontrolü (permissions.access_level)
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
            $hasAccess = PermissionHelper::checkVisibilityScope($permissionMeta['access_level'], $userId, $context);

            return [
                'success' => $hasAccess,
                'message' => $hasAccess ? 'Permission granted by access_level.' : 'Permission denied by access_level.'
            ];
        }

        return [
            'success' => false,
            'message' => 'Permission denied: no matching conditions found.',
            'statusCode' => 403
        ];
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
        try {
            $permissions = $this->crudHandler->read('permissions', [
                'scope' => $scope
            ], ['code', 'description'], true);
    
            if ($permissions instanceof \Illuminate\Support\Collection) {
                $permissions = $permissions->toArray();
            }
    
            $mapped = array_map(function ($item) {
                return is_array($item)
                    ? ['code' => $item['code'], 'description' => $item['description'] ?? $item['code']]
                    : ['code' => $item->code, 'description' => $item->description ?? $item->code];
            }, $permissions);
    
            return [
                'success' => true,
                'message' => 'All permissions retrieved.',
                'data' => ['permissions' => $mapped]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to fetch permissions.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
    public function getAllUserPermissions(?int $entityId = null, string $entityType = 'company'): array {
        try {
            $userId = $this->userId;
    
            if ($this->userRoleId == 1) {
                $all = $this->crudHandler->read('permissions', [], ['code'], true);
                if ($all instanceof \Illuminate\Support\Collection) {
                    $all = $all->toArray();
                }
                $codes = array_map(fn($item) => is_array($item) ? $item['code'] : $item->code, $all);
                return [
                    'success' => true,
                    'message' => 'Admin has all permissions.',
                    'data' => ['permissions' => $codes]
                ];
            }
    
            $permissions = [];
    
            // Kullanıcıya özel izinler
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
    
            // Rol üzerinden gelen izinler
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
    
            return [
                'success' => true,
                'message' => 'Permissions loaded.',
                'data' => ['permissions' => array_values(array_unique($permissions))]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error retrieving user permissions.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }    
    public function updateUserPermissions(int $targetUserId, int $companyId, array $permissionCodes): array {
        try {
            $permissions = [];
            foreach ($permissionCodes as $code) {
                $perm = $this->crudHandler->read('permissions', ['code' => $code], ['id'], false);
                if ($perm && isset($perm['id'])) {
                    $permissions[] = $perm['id'];
                }
            }
            $user = $this->crudHandler->read('users', ['id' => $targetUserId], ['sPermission'], false);
            $json = [];
            if ($user && !empty($user['sPermission'])) {
                $json = json_decode($user['sPermission'], true);
            }
            $json[$companyId] = $permissions;

            $this->crudHandler->update('users', ['id' => $targetUserId], [
                'sPermission' => json_encode($json)
            ]);

            return [
                'success' => true,
                'message' => 'User permissions updated in sPermission.'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update user permissions.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
    public function assignUserPermission(array $data): array {
        $userId = $data['user_id'] ?? null;
        $permissionCode = $data['permission_code'] ?? null;
        $companyId = $data['company_id'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
    
        if (!$userId || !$permissionCode) {
            return [
                'success' => false,
                'message' => 'User ID and permission code are required.',
                'statusCode' => 400
            ];
        }
    
        try {
            $success = $this->crudHandler->create('user_permissions', [
                'user_id' => $userId,
                'permission_code' => $permissionCode,
                'company_id' => $companyId,
                'expires_at' => $expiresAt
            ]);
    
            return [
                'success' => (bool) $success,
                'message' => $success ? 'Permission assigned.' : 'Failed to assign permission.'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error occurred.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
    public function assignRolePermission(string $role, string $permissionCode): array {
        try {
            // Zaten var mı kontrol et
            $existing = $this->crudHandler->read('role_permissions', [
                'role' => $role,
                'permission_code' => $permissionCode
            ], ['id'], false);
    
            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'This role already has the permission.',
                    'statusCode' => 409
                ];
            }
    
            // Ekle
            $result = $this->crudHandler->create('role_permissions', [
                'role' => $role,
                'permission_code' => $permissionCode
            ]);
    
            return [
                'success' => (bool) $result,
                'message' => $result ? 'Permission assigned to role.' : 'Failed to assign permission.'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error assigning permission to role.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }    
    public function getUserPermissions(int $userId, ?int $companyId = null): array {
        $conditions = ['user_id' => $userId];
        if (!is_null($companyId)) {
            $conditions['company_id'] = $companyId;
        }
    
        $permissions = $this->crudHandler->read('user_permissions', $conditions, ['permission_code'], true);
    
        if ($permissions instanceof \Illuminate\Support\Collection) {
            $permissions = $permissions->toArray();
        }
    
        return array_map(function ($item) {
            return is_array($item) ? $item['permission_code'] : $item->permission_code;
        }, $permissions);
    }
    public function removeUserPermission(int $userId, string $permissionCode, ?int $companyId = null): array {
        try {
            $conditions = [
                'user_id' => $userId,
                'permission_code' => $permissionCode
            ];
    
            if (!is_null($companyId)) {
                $conditions['company_id'] = $companyId;
            }
    
            $deleted = $this->crudHandler->delete('user_permissions', $conditions);
    
            if ($deleted) {
                return [
                    'success' => true,
                    'message' => 'Permission removed successfully.'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'No matching permission found.'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error removing permission.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
    public function getPermissionDetails(string $code): array {
        try {
            $permission = $this->crudHandler->read('permissions', [
                'code' => $code
            ], ['code', 'description', 'access_level', 'scope'], false);
    
            if (!$permission) {
                return [
                    'success' => false,
                    'message' => 'Permission not found.',
                    'statusCode' => 404
                ];
            }
    
            if ($permission instanceof \stdClass) {
                $permission = (array) $permission;
            }
    
            return [
                'success' => true,
                'message' => 'Permission details retrieved.',
                'data' => $permission
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error retrieving permission details.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
    public function listRolePermissions(string $role): array {
        try {
            $permissions = $this->crudHandler->read('role_permissions', [
                'role' => $role
            ], ['permission_code'], true);
    
            if ($permissions instanceof \Illuminate\Support\Collection) {
                $permissions = $permissions->toArray();
            }
    
            $codes = array_map(function ($item) {
                return is_array($item) ? $item['permission_code'] : $item->permission_code;
            }, $permissions);
    
            return [
                'success' => true,
                'message' => 'Permissions for role retrieved.',
                'data' => ['permissions' => $codes]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve role permissions.',
                'errors' => ['exception' => $e->getMessage()],
                'statusCode' => 500
            ];
        }
    }
}