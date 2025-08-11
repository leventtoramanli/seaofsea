<?php

require_once __DIR__ . '/../core/PermissionService.php';

class PermissionHandler
{
    public static function check(array $params): array
    {
        $auth = Auth::requireAuth();

        $permissionCode = $params['permission_code'] ?? null;
        $companyId = $params['company_id'] ?? null;

        if (!$permissionCode) {
            return ['success' => false, 'message' => 'permission_code is required'];
        }

        $has = PermissionService::hasPermission($auth['user_id'], $permissionCode, $companyId);
        return ['success' => true, 'allowed' => $has];
    }

    public static function getAll(array $params): array
    {
        Auth::requireAuth();
        $crud = new Crud(); // readonly
        $scope = $params['scope'] ?? 'global';

        $rows = $crud->read('permissions', ['scope' => $scope]) ?: [];
        $list = array_map(fn($r) => [
            'code' => $r['code'],
            'description' => $r['description'],
            'category' => $r['category'],
            'scope' => $r['scope'],
            'min_role' => $r['min_role'],
            'is_public' => (int)$r['is_public'],
        ], $rows);

        return ['success' => true, 'permissions' => $list];
    }

    public static function assign(array $params): array
    {
        $auth = Auth::requireAuth();

        $targetUserId   = (int)($params['user_id'] ?? 0);
        $permissionCode = $params['permission_code'] ?? null;
        $companyId      = isset($params['company_id']) ? (int)$params['company_id'] : null;
        $note           = $params['note'] ?? null;
        $expiresAt      = $params['expires_at'] ?? null;

        if (!$targetUserId || !$permissionCode) {
            return ['success' => false, 'message' => 'user_id and permission_code are required'];
        }

        // (Opsiyonel) burada Gate::check('permission.assign') çağrılabilir.
        $ok = PermissionService::assignPermission($targetUserId, $permissionCode, $companyId, $auth['user_id'], $note, $expiresAt);
        return ['success' => $ok];
    }

    public static function revoke(array $params): array
    {
        $auth = Auth::requireAuth();

        $targetUserId   = (int)($params['user_id'] ?? 0);
        $permissionCode = $params['permission_code'] ?? null;
        $companyId      = isset($params['company_id']) ? (int)$params['company_id'] : null;
        $note           = $params['note'] ?? null;

        if (!$targetUserId || !$permissionCode) {
            return ['success' => false, 'message' => 'user_id and permission_code are required'];
        }

        // (Opsiyonel) Gate::check('permission.revoke')
        $ok = PermissionService::revokePermission($targetUserId, $permissionCode, $companyId, $auth['user_id'], $note);
        return ['success' => $ok];
    }
    public static function effective(array $params): array
    {
        $auth = Auth::requireAuth();
        $userId = (int)($params['user_id'] ?? $auth['user_id']);
        $companyId = isset($params['company_id']) ? (int)$params['company_id'] : null;

        $perms = PermissionService::getUserPermissions($userId, $companyId);
        return ['success' => true, 'effective' => $perms];
    }
}
