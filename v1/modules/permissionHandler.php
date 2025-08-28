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
            'code'         => $r['code'],
            'description'  => $r['description'],
            'category'     => $r['category'],
            'scope'        => $r['scope'],
            'min_role'     => $r['min_role'],
            'access_level' => $r['access_level'],   // <-- EKLENDİ
            'is_public'    => (int)$r['is_public'],
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
        $actor = (int)$auth['user_id'];

        $targetUserId = (int)($params['user_id'] ?? 0);
        $companyId    = (int)($params['company_id'] ?? 0);
        $newCodes     = is_array($params['permission_codes'] ?? null) ? $params['permission_codes'] : [];
        $roleId       = isset($params['role_id']) ? (int)$params['role_id'] : null;

        if ($targetUserId <= 0 || $companyId <= 0) {
            return ['success'=>false, 'message'=>'user_id and company_id are required'];
        }

        // --- Şirket kaydı + creator/admin tespiti
        $c = $crud->read('companies', ['id'=>$companyId], ['id','created_by'], false);
        if (!$c) return ['success'=>false, 'message'=>'company_not_found'];

        $isCreator = (int)$c['created_by'] === $actor;
        $isAdmin = (bool)$crud->query("
            SELECT 1 FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            AND r.scope='company' AND r.name='admin' LIMIT 1
        ", [':u'=>$actor, ':c'=>$companyId]);

        $wantsRoleChange = ($roleId !== null);

        // --- Yetki kararları
        $canEditPerms = $isCreator || $isAdmin ||
            PermissionService::hasPermission($actor, 'company.permissions.manage', $companyId);

        $canEditRole  = $isCreator || $isAdmin ||
            PermissionService::hasPermission($actor, 'company.roles.update', $companyId);

        if ($wantsRoleChange) {
            if (!$canEditRole) {
                return ['success'=>false, 'message'=>'not_authorized'];
            }

            // Rol değişimini tek noktaya delege et (son-admin kilidi vb. için daha güvenli)
            if (!class_exists('CompanyHandler') || !method_exists('CompanyHandler','setMemberRole')) {
                // Geriye uyumluluk: doğrudan update fallback (tercihen kullanma)
                $ok = $crud->update('company_users',
                    ['role_id'=>$roleId, 'updated_at'=>date('Y-m-d H:i:s')],
                    ['company_id'=>$companyId, 'user_id'=>$targetUserId]
                );
                if (!$ok) return ['success'=>false, 'message'=>'role_update_failed'];
            } else {
                $res = CompanyHandler::setMemberRole([
                    'company_id' => $companyId,
                    'user_id'    => $targetUserId,
                    'role_id'    => $roleId,
                    // Bu akışta seed etmiyoruz; UI’da ayrıca buton/opsiyon istersen açarsın:
                    'seed_role_permissions'   => 0,
                    'resync_position_perms'   => 0,
                ]);
                if (!($res['updated'] ?? false)) {
                    return ['success'=>false, 'message'=>($res['error'] ?? 'role_update_failed')];
                }
            }
            // Rol güncellendi; izin senkronu yapmadan çıkılabilir ya da devam edilebilir.
            // UI aynı çağrıda permission checkbox’ları da yolluyorsa, aşağıdaki blok yine çalışsın diye
            // return etmiyoruz.
        } else {
            if (!$canEditPerms) {
                return ['success'=>false, 'message'=>'not_authorized'];
            }
        }

        // --- Permission checkbox senkronizasyonu (yalnız company-scope kodlarıyla sınırla)
        // Normalize & filtrele
        $newSet = array_values(array_unique(array_map('strval', $newCodes)));
        if ($newSet) {
            // Sadece scope='company' ve var olan kodlar
            $validRows = $crud->read('permissions', [
                'scope' => 'company',
                'code'  => ['IN', $newSet],
            ], ['code'], true) ?: [];
            $validSet = array_map(fn($r)=>(string)$r['code'], $validRows);
            $newSet   = array_values(array_intersect($newSet, $validSet));
        }

        // Mevcut efektif set (role + position + grant − revoke)
        $current = PermissionService::getUserPermissions($targetUserId, $companyId);
        $current = is_array($current) ? $current : [];

        // Diff: UI'da işaretli olanlar hedef; efektiften farkları al
        $toGrant  = array_diff($newSet, $current);   // ek işaretlenenler
        $toRevoke = array_diff($current, $newSet);   // kaldırılanlar (rol/pozisyon gelse dahi override için revoke edilir)

        foreach ($toGrant as $code) {
            PermissionService::assignPermission($targetUserId, $code, $companyId, $actor);
        }
        foreach ($toRevoke as $code) {
            PermissionService::revokePermission($targetUserId, $code, $companyId, $actor);
        }

        return ['success'=>true];
    }
    public static function matrix(array $params): array
    {
        $auth   = Auth::requireAuth();
        $actor  = (int)$auth['user_id'];
        $crud   = new Crud($actor);

        $companyId = (int)($params['company_id'] ?? 0);
        $targetId  = (int)($params['user_id'] ?? 0);
        if ($companyId <= 0 || $targetId <= 0) {
            return ['success'=>false, 'message'=>'company_id and user_id are required'];
        }

        // --- Yetki: şirket kurucusu || company-admin || company.members.update || company.roles.update
        $c = $crud->read('companies', ['id'=>$companyId], ['id','created_by'], false);
        if (!$c) return ['success'=>false, 'message'=>'company_not_found'];

        // --- Yetki: creator/admin veya ilgili izinler
        $isCreator = (int)$c['created_by'] === $actor;
        $isAdmin = (bool)$crud->query("
            SELECT 1 FROM company_users cu
            JOIN roles r ON r.id=cu.role_id
            WHERE cu.user_id=:u AND cu.company_id=:c
            AND r.scope='company' AND r.name='admin' LIMIT 1
        ", [':u'=>$actor, ':c'=>$companyId]);

        $canPermsManage  = PermissionService::hasPermission($actor, 'company.permissions.manage', $companyId);
        $canPermsView    = PermissionService::hasPermission($actor, 'company.permissions.view',   $companyId);
        $canMembersUpd   = PermissionService::hasPermission($actor, 'company.members.update',     $companyId);
        $canRolesUpd     = PermissionService::hasPermission($actor, 'company.roles.update',       $companyId);

        if (!($isCreator || $isAdmin || $canPermsManage || $canPermsView || $canMembersUpd || $canRolesUpd)) {
            return ['success'=>false, 'message'=>'not_authorized'];
        }

        // --- 1) Tüm company-scope permission kataloğu (liste ekranı için)
        $permRows = $crud->read('permissions', ['scope'=>'company']) ?: [];
        $items = array_map(fn($r)=>[
            'code'         => (string)$r['code'],
            'category'     => (string)($r['category'] ?? ''),
            'description'  => (string)($r['description'] ?? ''),
            'min_role'     => (string)($r['min_role'] ?? 'viewer'),
            'access_level' => (string)($r['access_level'] ?? 'all'),
            'is_public'    => (int)($r['is_public'] ?? 0),
        ], $permRows);

        // Kısa yardımcılar
        $pluckCodes = function(array $rows, string $key='permission_code'): array {
            $out = [];
            foreach ($rows as $r) {
                $v = (string)($r[$key] ?? '');
                if ($v !== '') $out[$v] = true;
            }
            return array_keys($out);
        };

        // --- 2) Efektif set (revoke > grant > role/position)
        $effective = PermissionService::getUserPermissions($targetId, $companyId);

        // --- 3) Kullanıcıya ait GRANT/REVOKE (hem global hem company) — UI rozetleri için
        $ur = $crud->query("
            SELECT permission_code, action, company_id
            FROM user_permissions
            WHERE user_id=:u
            AND (company_id IS NULL OR company_id=:c)
        ", [':u'=>$targetId, ':c'=>$companyId]) ?: [];

        $grantsCompany = [];
        $grantsGlobal  = [];
        $revokesCompany= [];
        $revokesGlobal = [];
        foreach ($ur as $row) {
            $code = (string)$row['permission_code'];
            $act  = (string)($row['action'] ?? 'grant');
            $cid  = $row['company_id'] ?? null;
            $isCompany = isset($cid) && ((int)$cid === $companyId);
            if ($act === 'revoke') {
                $isCompany ? $revokesCompany[$code]=true : $revokesGlobal[$code]=true;
            } else {
                $isCompany ? $grantsCompany[$code]=true : $grantsGlobal[$code]=true;
            }
        }
        $grantsCompany = array_keys($grantsCompany);
        $grantsGlobal  = array_keys($grantsGlobal);
        $revokesCompany= array_keys($revokesCompany);
        $revokesGlobal = array_keys($revokesGlobal);

        // --- 4) Rol ve pozisyon kaynakları (kaynak rozetleri için)
        // Company role
        $cu = $crud->read('company_users',
            ['company_id'=>$companyId, 'user_id'=>$targetId],
            ['role_id','position_id'], false
        );
        $roleCompanyCodes = [];
        if ($cu && !empty($cu['role_id'])) {
            $rp = $crud->read('role_permissions', ['role_id'=>(int)$cu['role_id']]) ?: [];
            $roleCompanyCodes = $pluckCodes($rp);
        }

        // Global role
        $u = $crud->read('users', ['id'=>$targetId], ['role_id'], false);
        $roleGlobalCodes = [];
        if ($u && !empty($u['role_id'])) {
            $rp = $crud->read('role_permissions', ['role_id'=>(int)$u['role_id']]) ?: [];
            $roleGlobalCodes = $pluckCodes($rp);
        }

        // Position defaults
        $positionCodes = [];
        if ($cu && !empty($cu['position_id'])) {
            $pos = $crud->read('company_positions', ['id'=>(int)$cu['position_id']], ['permission_codes'], false);
            if ($pos && !empty($pos['permission_codes'])) {
                $dec = json_decode((string)$pos['permission_codes'], true);
                if (is_array($dec)) {
                    foreach ($dec as $code) {
                        $code = trim((string)$code);
                        if ($code !== '') $positionCodes[$code] = true;
                    }
                }
            }
            $positionCodes = array_keys($positionCodes);
        }

        // --- 5) Dönüş
        return [
            'success'   => true,
            'items'     => $items,          // tüm company izinleri (liste/checkbox’lar)
            'effective' => $effective,      // checked olacaklar

            // Kaynak dökümleri (UI rozetleri/tooltipleri için)
            'sources'   => [
                'grants_company'   => $grantsCompany,
                'grants_global'    => $grantsGlobal,
                'revokes_company'  => $revokesCompany,
                'revokes_global'   => $revokesGlobal,
                'role_company'     => $roleCompanyCodes,
                'role_global'      => $roleGlobalCodes,
                'position_company' => $positionCodes,
            ],
            // İsteğe bağlı küçük not
            'precedence' => 'revoke > grant > role/position',
        ];
    }
}
