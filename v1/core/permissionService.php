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
        self::initCrud($userId);
        $ck = self::companyKey($companyId);

        // Cache?
        if (isset(self::$cacheEffective[$userId][$ck])) {
            return self::$cacheEffective[$userId][$ck];
        }

        // 1) Role tabanlı set
        $roleCodes = [];

        // Global rol kodları
        $globalRoleId = self::getGlobalRoleId($userId);
        if ($globalRoleId) {
            foreach (self::getRolePermissionCodesByRoleId($globalRoleId) as $c) {
                $roleCodes[$c] = true;
            }
        }

        // Company rol kodları (isteğe bağlı)
        if ($companyId) {
            $companyRoleId = self::getCompanyRoleId($userId, $companyId);
            if ($companyRoleId) {
                foreach (self::getRolePermissionCodesByRoleId($companyRoleId) as $c) {
                    $roleCodes[$c] = true;
                }
            }
        }

        // 2) Kullanıcı bazlı grants/revokes (global + company)
        $ur = self::getUserGrantsAndRevokes($userId, $companyId);
        $grants  = array_fill_keys($ur['grants'], true);
        $revokes = array_fill_keys($ur['revokes'], true);

        // 3) Birleştir (revoke > grant > role)
        $effective = $roleCodes + $grants;         // birlik (keys)
        $effective = array_diff_key($effective, $revokes);

        $out = array_keys($effective);
        sort($out);

        // Cache’e yaz
        self::$cacheEffective[$userId][$ck] = $out;
        return $out;
    }

    /**
     * Tek bir izin için kontrol (cache kullanır).
     * companyId verilirse hem company-scoped hem de global kayıtları dikkate alır.
     */
    public static function hasPermission(int $userId, string $permissionCode, ?int $companyId = null): bool
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
    }

    /** Grant izin (global için companyId NULL bırak) */
    public static function assignPermission(
        int $userId,
        string $permissionCode,
        ?int $companyId = null,
        ?int $grantedBy = null,
        ?string $note = null,
        ?string $expiresAt = null
    ): bool {
        self::initCrud($userId);

        // Global veya company revoke varsa temizle
        self::$crud->query("
            DELETE FROM user_permissions
            WHERE user_id = :uid AND permission_code = :code
              AND IFNULL(company_id,0) = IFNULL(:cid,0) AND action = 'revoke'
        ", [':uid' => $userId, ':code' => $permissionCode, ':cid' => $companyId]);

        // Zaten grant varsa dokunma
        $exists = self::$crud->read('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'grant'
        ], false);
        if ($exists) {
            self::invalidateCache($userId, $companyId);
            return true;
        }

        $ok = self::$crud->create('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'grant',
            'granted_by'      => $grantedBy,
            'note'            => $note,
            'expires_at'      => $expiresAt,
            'created_at'      => date('Y-m-d H:i:s'),
        ]) !== false;

        self::invalidateCache($userId, $companyId);
        return $ok;
    }

    /** Revoke izin (rol ile geleni de etkisiz bırakır) */
    public static function revokePermission(
        int $userId,
        string $permissionCode,
        ?int $companyId = null,
        ?int $grantedBy = null,
        ?string $note = null
    ): bool {
        self::initCrud($userId);

        // Zaten revoke varsa idempotent
        $exists = self::$crud->read('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'revoke'
        ], false);
        if ($exists) {
            self::invalidateCache($userId, $companyId);
            return true;
        }

        $ok = self::$crud->create('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'revoke',
            'granted_by'      => $grantedBy,
            'note'            => $note,
            'created_at'      => date('Y-m-d H:i:s'),
        ]) !== false;

        self::invalidateCache($userId, $companyId);
        return $ok;
    }

    /** Cache temizleme yardımcıları */
    private static function invalidateCache(int $userId, ?int $companyId = null): void
    {
        $ck = self::companyKey($companyId);
        unset(self::$cacheEffective[$userId][$ck]);
        unset(self::$cacheHasPerm[$userId][$ck]); // tüm per-code girişleri gider
    }
}
