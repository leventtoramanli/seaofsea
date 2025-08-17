<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
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

    public static function get_all_permissions(array $params): array { return self::getAll($params); }

    public static function get_user_permissions(array $params): array
    {
        $auth = Auth::requireAuth();
        $userId    = (int)($params['user_id'] ?? $auth['user_id']);
        $companyId = isset($params['company_id']) ? (int)$params['company_id'] : null;

        $perms = PermissionService::getUserPermissions($userId, $companyId);
        return ['success'=>true, 'permissions'=> $perms ?: []];
    }

    public static function update_user_permissions(array $params): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $targetUserId = (int)($params['user_id'] ?? 0);
        $companyId    = (int)($params['company_id'] ?? 0);
        $newCodes     = is_array($params['permission_codes'] ?? null) ? $params['permission_codes'] : [];
        $roleId       = isset($params['role_id']) ? (int)$params['role_id'] : null;

        if ($targetUserId<=0 || $companyId<=0) {
            return ['success'=>false, 'message'=>'user_id and company_id are required'];
        }

        // yalnızca şirket admin'i değiştirebilsin
        $meRow = $crud->query("
            SELECT r.name AS role
            FROM company_users cu
            LEFT JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            LIMIT 1
        ", [':u'=>$auth['user_id'], ':c'=>$companyId]);
        if (!$meRow || ($meRow[0]['role']??'') !== 'admin') {
            return ['success'=>false, 'message'=>'not_authorized'];
        }

        // rol güncellemesi
        if ($roleId !== null) {
            $crud->update('company_users',
                ['role_id'=>$roleId, 'updated_at'=>date('Y-m-d H:i:s')],
                ['company_id'=>$companyId, 'user_id'=>$targetUserId]
            );
        }

        // permission senkronizasyonu (ekle/çıkar)
        $current = PermissionService::getUserPermissions($targetUserId, $companyId);
        $current = is_array($current) ? $current : [];

        $newSet   = array_values(array_unique(array_map('strval', $newCodes)));
        $toGrant  = array_diff($newSet, $current);
        $toRevoke = array_diff($current, $newSet);

        foreach ($toGrant as $code) {
            PermissionService::assignPermission($targetUserId, $code, $companyId, $auth['user_id']);
        }
        foreach ($toRevoke as $code) {
            PermissionService::revokePermission($targetUserId, $code, $companyId, $auth['user_id']);
        }

        return ['success'=>true];
    }
}
