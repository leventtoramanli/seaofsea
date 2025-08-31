<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Crud.php';

class PermissionService
{
    /** Crud: permissionGuard=false (RBAC döngüsünü kırmak için) */
    private static Crud $crud;

    /** Küçük bellek içi cache’ler (istek ömrü boyunca geçerli) */
    private static array $cacheEffective = [];   // [userId][companyKey:int] = string[]
    private static array $cacheHasPerm   = [];   // [userId][companyKey:int][code] = bool
    private static array $permMetaCache  = [];   // [code] = ['scope'=>'global|company','is_public'=>0/1]

    public static function loadOverlay(Crud $crud, int $userId, int $companyId = 0): array
    {
        $row = $crud->read('user_company_perms',
            ['user_id'=>$userId, 'company_id'=>$companyId],
            ['permissions_json','version','updated_at'],
            false
        );
        if (!$row || empty($row['permissions_json'])) {
            return ['v'=>1, 'perms'=>[]];
        }
        $j = json_decode((string)$row['permissions_json'], true);
        if (!is_array($j)) $j = [];
        $j += ['v'=>1, 'perms'=>[]];
        if (!is_array($j['perms'])) $j['perms'] = [];
        return $j;
    }

    // --- JSON overlay'i patchle (grant/revoke) ---
    public static function upsertOverlay(Crud $crud, int $actorId, int $userId, int $companyId, string $code, string $action, ?string $note=null, ?string $expiresAt=null): bool
    {
        $now = date('Y-m-d H:i:s');
        $row = $crud->read('user_company_perms', ['user_id'=>$userId, 'company_id'=>$companyId], ['id','permissions_json','version'], false);

        $obj = ['v'=>1, 'perms'=>[]];
        if ($row && !empty($row['permissions_json'])) {
            $dec = json_decode((string)$row['permissions_json'], true);
            if (is_array($dec)) {
                $obj = $dec + ['v'=>1];
                if (!isset($obj['perms']) || !is_array($obj['perms'])) $obj['perms'] = [];
            }
        }

        $obj['perms'][$code] = array_filter([
            'a'   => ($action === 'revoke' ? 'revoke' : 'grant'),
            'by'  => $actorId,
            'at'  => $now,
            'exp' => $expiresAt ?: null,
            'note'=> $note ?: null,
        ], fn($v)=> $v !== null);

        $json = json_encode($obj, JSON_UNESCAPED_UNICODE);
        if (!$row) {
            // insert
            return (bool)$crud->create('user_company_perms', [
                'user_id' => $userId,
                'company_id' => $companyId,
                'permissions_json' => $json,
                'version' => 1,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            // optimistic lock basit versiyon
            return (bool)$crud->update('user_company_perms', [
                'permissions_json' => $json,
                'version' => (int)$row['version'] + 1,
                'updated_by' => $actorId,
                'updated_at' => $now,
            ], ['id'=>(int)$row['id']]);
        }
    }

    // --- role taban set ---
    public static function baseRoleCodes(Crud $crud, int $userId, int $companyId): array
    {
        // şirket kurucusu mu?
        $c = $crud->read('companies', ['id'=>$companyId], ['created_by'], false);
        if ($c && (int)$c['created_by'] === $userId) {
            return self::allCompanyCodes($crud); // kurucuya full company scope
        }

        // company admin mi? (role_id = 4)
        $row = $crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
              AND r.scope='company' AND r.name='admin'
            LIMIT 1
        ", [':u'=>$userId, ':c'=>$companyId]);
        if ($row) {
            return self::allCompanyCodes($crud);
        }

        // normal role
        $r = $crud->query("
            SELECT rp.permission_code
            FROM company_users cu
            JOIN role_permissions rp ON rp.role_id = cu.role_id
            JOIN roles r ON r.id = cu.role_id AND r.scope='company'
            WHERE cu.user_id = :u AND cu.company_id = :c
        ", [':u'=>$userId, ':c'=>$companyId]) ?: [];

        return array_values(array_unique(array_map(fn($x)=>(string)$x['permission_code'], $r)));
    }

    private static function allCompanyCodes(Crud $crud): array
    {
        $rows = $crud->query("SELECT code FROM permissions WHERE scope='company'") ?: [];
        return array_values(array_unique(array_map(fn($x)=>(string)$x['code'], $rows)));
    }

    // --- efektif ---
    public static function effective(Crud $crud, int $userId, int $companyId=0): array
    {
        $base = $companyId > 0 ? self::baseRoleCodes($crud, $userId, $companyId) : []; // global’de role yoksa boş
        $set = array_fill_keys($base, true);

        // global overlay
        $g = self::loadOverlay($crud, $userId, 0);
        // company overlay (varsa)
        $o = $companyId > 0 ? self::loadOverlay($crud, $userId, $companyId) : ['perms'=>[]];

        foreach ([$g['perms'], $o['perms']] as $perms) {
            foreach ($perms as $code => $meta) {
                if (!is_array($meta)) continue;
                $a = $meta['a'] ?? null;
                $exp = $meta['exp'] ?? null;
                if ($exp && strtotime($exp) < time()) continue; // süresi bitmiş
                if ($a === 'grant')   { $set[$code] = true; }
                elseif ($a === 'revoke') { unset($set[$code]); }
            }
        }

        return array_keys($set);
    }

    private static function initCrud(int $userContextId): void
    {
        // Bu serviste yalnız DB okuma/yazma yapıyoruz; tablolara erişimde guard kapalı.
        self::$crud = new Crud($userContextId, false);
    }

    private static function companyKey(?int $companyId): int
    {
        return $companyId ? (int)$companyId : 0;
    }

    /** permissions tablosundan scope/is_public bilgisini getir (ve cache’le) */
    private static function getPermissionMeta(string $permissionCode): ?array
    {
        if (isset(self::$permMetaCache[$permissionCode])) {
            return self::$permMetaCache[$permissionCode];
        }
        $row = self::$crud->read('permissions', ['code' => $permissionCode], false);
        if (!$row) return null;

        $meta = [
            'scope'     => (string)($row['scope'] ?? 'global'),
            'is_public' => (int)($row['is_public'] ?? 0),
        ];
        self::$permMetaCache[$permissionCode] = $meta;
        return $meta;
    }

    public static function isGlobalAdminUser(int $userId): bool
    {
        self::initCrud($userId);
        $u = self::$crud->read('users', ['id'=>$userId], ['role_id'], false);
        if (!$u || empty($u['role_id'])) return false;

        $r = self::$crud->read('roles', ['id'=>(int)$u['role_id']], ['scope','name'], false);
        return $r && ($r['scope'] === 'global') && (strtolower((string)$r['name']) === 'admin');
    }

    /** Kullanıcının bir şirketteki rol_id’sini döndürür (yoksa null).
     *  Üye kaydı yoksa ve kullanıcı şirketin kurucusu ise company-admin rol ID’si fallback yapılır. */
    private static function getCompanyRoleId(int $userId, int $companyId): ?int
    {
        $r = self::$crud->query("
            SELECT cu.role_id
            FROM company_users cu
            WHERE cu.user_id = :u AND cu.company_id = :c
            LIMIT 1
        ", [':u' => $userId, ':c' => $companyId]);
        $rid = $r && !empty($r[0]['role_id']) ? (int)$r[0]['role_id'] : null;
        if ($rid) return $rid;

        // Fallback: kurucu ise admin say
        $c = self::$crud->read('companies', ['id' => $companyId], ['created_by'], false);
        if ($c && (int)$c['created_by'] === $userId) {
            $adm = self::$crud->read('roles', ['scope' => 'company', 'name' => 'admin'], ['id'], false);
            if ($adm && !empty($adm['id'])) {
                return (int)$adm['id'];
            }
        }

        if (self::isGlobalAdminUser($userId)) {
            $adm = self::$crud->read('roles', ['scope' => 'company', 'name' => 'admin'], ['id'], false);
            if ($adm && !empty($adm['id'])) return (int)$adm['id'];
        }

        return null;
    }

    /** Global (users.role_id) bilgisini döndürür (yoksa null) */
    private static function getGlobalRoleId(int $userId): ?int
    {
        $u = self::$crud->read('users', ['id' => $userId], ['role_id'], false);
        $rid = $u && !empty($u['role_id']) ? (int)$u['role_id'] : null;
        return $rid ?: null;
    }

    /** Verilen role_id için role_permissions’dan kodları al */
    private static function getRolePermissionCodesByRoleId(int $roleId): array
    {
        $rows = self::$crud->read('role_permissions', ['role_id' => $roleId]) ?: [];
        $codes = [];
        foreach ($rows as $rp) {
            $code = (string)($rp['permission_code'] ?? '');
            if ($code !== '') $codes[$code] = true;
        }
        return array_keys($codes);
    }

    /** Kullanıcının (global + company) GRANT/REVOKE kayıtlarını getirir; expiry’yi filtreler. */
    private static function getUserGrantsAndRevokes(int $userId, ?int $companyId): array
    {
        // Şirket bağlamı varsa GLOBAL + COMPANY birlikte, yoksa yalnız GLOBAL
        if ($companyId === null) {
            $rows = self::$crud->query("
                SELECT user_id, permission_code, action, company_id, expires_at
                FROM user_permissions
                WHERE user_id = :u AND company_id IS NULL
            ", [':u' => $userId]) ?: [];
        } else {
            $rows = self::$crud->query("
                SELECT user_id, permission_code, action, company_id, expires_at
                FROM user_permissions
                WHERE user_id = :u AND (company_id IS NULL OR company_id = :c)
            ", [':u' => $userId, ':c' => $companyId]) ?: [];
        }

        $now = new DateTimeImmutable('now');
        $grants = [];
        $revokes = [];
        foreach ($rows as $r) {
            // Expire kontrolü
            $exp = $r['expires_at'] ?? null;
            if (!empty($exp)) {
                try {
                    if ($now >= new DateTimeImmutable($exp)) continue; // süresi geçen kayıtları atla
                } catch (\Throwable $e) {
                    continue;
                }
            }
            $code = (string)$r['permission_code'];
            if (($r['action'] ?? 'grant') === 'revoke') {
                $revokes[$code] = true; // revoke kazanır
            } else {
                $grants[$code] = true;
            }
        }

        return [
            'grants'  => array_keys($grants),
            'revokes' => array_keys($revokes),
        ];
    }

    /**
     * Kullanıcının efektif izin kodları (companyId verildiyse: company rolü + global rol + grants) − revokes
     * Öncelik: revoke > grant > role
     */
    public static function getUserPermissions(int $userId, ?int $companyId = null): array
    {
        // guard kapalı crud: yalnızca veri okuma/yazma
        $crud = new Crud($userId, false);
        $cid  = (int)($companyId ?? 0);

        $list = self::effective($crud, $userId, $cid); // overlay + baseRoleCodes
        sort($list);
        return $list;
    }
    
    public static function hasPermission(int $userId, string $code, ?int $companyId=null): bool
    {
        $crud = new Crud($userId, false);

        // kurucu/company-admin bypass (mevcut hızlı yol)
        if ($companyId) {
            $c = $crud->read('companies', ['id'=>$companyId], ['created_by'], false);
            if ($c && (int)$c['created_by'] === $userId) return true;
            $isAdmin = $crud->query("
                SELECT 1 FROM company_users cu
                JOIN roles r ON r.id=cu.role_id
                WHERE cu.user_id=:u AND cu.company_id=:c
                AND r.scope='company' AND r.name='admin' LIMIT 1
            ", [':u'=>$userId, ':c'=>$companyId]);
            if ($isAdmin) return true;
        }

        $eff = self::effective($crud, $userId, (int)($companyId ?? 0));
        return in_array($code, $eff, true);
    }

    /**
     * Tek bir izin için kontrol (cache kullanır).
     * companyId verilirse hem company-scoped hem de global kayıtları dikkate alır.
     */
    /*public static function hasPermission(int $userId, string $permissionCode, ?int $companyId = null): bool
    {
        self::initCrud($userId);
        $ck = self::companyKey($companyId);

        // Küçük per-code cache
        if (isset(self::$cacheHasPerm[$userId][$ck][$permissionCode])) {
            return self::$cacheHasPerm[$userId][$ck][$permissionCode];
        }

        // 0) is_public ise doğrudan geç
        $meta = self::getPermissionMeta($permissionCode);
        if ($meta && (int)$meta['is_public'] === 1) {
            self::$cacheHasPerm[$userId][$ck][$permissionCode] = true;
            return true;
        }

        // 1) Kullanıcıya ait REVOKE/GRANT kayıtları (global + company) — revoke > grant
        $ur = self::getUserGrantsAndRevokes($userId, $companyId);
        if (in_array($permissionCode, $ur['revokes'], true)) {
            self::$cacheHasPerm[$userId][$ck][$permissionCode] = false;
            return false;
        }
        if (in_array($permissionCode, $ur['grants'], true)) {
            self::$cacheHasPerm[$userId][$ck][$permissionCode] = true;
            return true;
        }

        // 2) Role-based — permission scope’a göre seçim yap
        $allowed = false;
        $scope   = $meta['scope'] ?? 'global';

        if ($scope === 'company') {
            // Şirket bağlamı gerekli; yoksa (grant da yoksa) false
            if ($companyId) {
                $cRole = self::getCompanyRoleId($userId, $companyId);
                if ($cRole) {
                    $rp = self::$crud->read('role_permissions', [
                        'role_id' => $cRole,
                        'permission_code' => $permissionCode
                    ], false);
                    if (!empty($rp)) $allowed = true;
                }
            } else {
                $allowed = false;
            }
        } else {
            // Global permission → global role
            $gRole = self::getGlobalRoleId($userId);
            if ($gRole) {
                $rp = self::$crud->read('role_permissions', [
                    'role_id' => $gRole,
                    'permission_code' => $permissionCode
                ], false);
                if (!empty($rp)) $allowed = true;
            }
        }

        self::$cacheHasPerm[$userId][$ck][$permissionCode] = $allowed;
        return $allowed;
    }*/

    /** Grant izin (global için companyId NULL bırak) */
    public static function assignPermission(
        int $userId,
        string $permissionCode,
        ?int $companyId = null,
        ?int $grantedBy = null,
        ?string $note = null,
        ?string $expiresAt = null
    ): bool {
        $actorId = $grantedBy ?: $userId;
        $crud    = new Crud($actorId, false);
        return self::upsertOverlay(
            $crud, $actorId, $userId, (int)($companyId ?? 0),
            $permissionCode, 'grant', $note, $expiresAt
        );
    }

    public static function revokePermission(
        int $userId,
        string $permissionCode,
        ?int $companyId = null,
        ?int $grantedBy = null,
        ?string $note = null
    ): bool {
        $actorId = $grantedBy ?: $userId;
        $crud    = new Crud($actorId, false);
        return self::upsertOverlay(
            $crud, $actorId, $userId, (int)($companyId ?? 0),
            $permissionCode, 'revoke', $note, null
        );
    }

    /** Cache temizleme yardımcıları */
    private static function invalidateCache(int $userId, ?int $companyId = null): void
    {
        $ck = self::companyKey($companyId);
        unset(self::$cacheEffective[$userId][$ck]);
        unset(self::$cacheHasPerm[$userId][$ck]); // tüm per-code girişleri gider
    }
}
