<?php
require_once __DIR__ . '/../handlers/UserHandler.php';
require_once __DIR__ . '/../handlers/CRUDHandlers.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class PermissionHandler {
    private $crudHandler;
    private $userId;

    public function __construct() {
        $this->crudHandler = new CRUDHandler();
        $userId = UserHandler::getUserIdFromToken();
        if (!$userId) {
            http_response_code(401);
            jsonResponse(false, 'Unauthorized');
        }
        $this->userId = $userId;
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

    public function getAllUserPermissions(?int $entityId = null, string $entityType = 'company'): array {
        $userId = $this->userId;
        $permissions = [];

        // 1. user_permissions
        $userPerms = $this->crudHandler->read('user_permissions', [
            'user_id' => $userId,
            [$entityType . '_id', '=', $entityId ?? 0],
            ['expires_at', 'IS', null]
        ], ['permission_code'], true);

        foreach ($userPerms as $p) {
            $permissions[] = $p['permission_code'];
        }

        // 2. role_permissions
        $role = $this->getUserEntityRole($entityId, $entityType);
        if ($role) {
            $rolePerms = $this->crudHandler->read('role_permissions', [
                'role' => $role
            ], ['permission_code'], true);

            foreach ($rolePerms as $p) {
                $permissions[] = $p['permission_code'];
            }
        }

        return array_values(array_unique($permissions));
    }
}