<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Crud.php';

class PermissionService
{
    private static Crud $crud;

    private static function initCrud(int $userId): void
    {
        if (!isset(self::$crud)) {
            self::$crud = new Crud($userId);
        } else {
            // aynı request içinde farklı user için çağrılırsa
            self::$crud = new Crud($userId);
        }
    }

    /**
     * Core check: user -> (revoke > grant) -> role
     * companyId şu an sistem izinlerinde genelde NULL olacak; parametre opsiyoneldir.
     */
    public static function hasPermission(int $userId, string $permissionCode, ?int $companyId = null): bool
    {
        self::initCrud($userId);

        // 1) user_permissions (revoke/grant) — exp süresi geçmişse yok say
        $conditions = [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
        ];
        if ($companyId !== null) {
            $conditions['company_id'] = $companyId;
        }

        // Kullanıcı kayıtlarını çek
        $userPerms = self::$crud->read('user_permissions', $conditions) ?: [];

        // Expire filtrele
        $now = new DateTimeImmutable('now');
        $userPerms = array_filter($userPerms, function ($row) use ($now) {
            if (empty($row['expires_at'])) return true;
            try {
                return $now < new DateTimeImmutable($row['expires_at']);
            } catch (\Throwable $e) {
                return false;
            }
        });

        // Revoke önceliklidir
        foreach ($userPerms as $row) {
            if (($row['action'] ?? 'grant') === 'revoke') {
                return false;
            }
        }
        // Grant varsa geç
        foreach ($userPerms as $row) {
            if (($row['action'] ?? 'grant') === 'grant') {
                return true;
            }
        }

        // 2) Role-based
        $user = self::$crud->read('users', ['id' => $userId], false);
        if (!$user || !isset($user['role_id']) || !$user['role_id']) {
            return false;
        }

        $rolePerm = self::$crud->read('role_permissions', [
            'role_id' => $user['role_id'],
            'permission_code' => $permissionCode
        ], false);

        return !empty($rolePerm);
    }

    /**
     * Kullanıcının efektif izin kodları (grant + role) - revoke çıkarılır - expire filtrelenir
     */
    public static function getUserPermissions(int $userId, ?int $companyId = null): array
    {
        self::initCrud($userId);

        // user grants & revokes
        $userCond = ['user_id' => $userId];
        if ($companyId !== null) {
            $userCond['company_id'] = $companyId;
        }
        $rows = self::$crud->read('user_permissions', $userCond) ?: [];

        $now = new DateTimeImmutable('now');
        $grants = [];
        $revokes = [];

        foreach ($rows as $r) {
            // expiry
            if (!empty($r['expires_at'])) {
                try {
                    if ($now >= new DateTimeImmutable($r['expires_at'])) continue;
                } catch (\Throwable $e) {
                    continue;
                }
            }
            $act = $r['action'] ?? 'grant';
            if ($act === 'revoke') {
                $revokes[$r['permission_code']] = true;
            } else {
                $grants[$r['permission_code']] = true;
            }
        }

        // role permissions
        $roleCodes = [];
        $user = self::$crud->read('users', ['id' => $userId], false);
        if ($user && !empty($user['role_id'])) {
            $rpList = self::$crud->read('role_permissions', ['role_id' => $user['role_id']]) ?: [];
            foreach ($rpList as $rp) {
                $roleCodes[$rp['permission_code']] = true;
            }
        }

        // birleşim - revoke çıkar
        $effective = array_keys(array_diff_key($grants + $roleCodes, $revokes));
        sort($effective);
        return $effective;
    }

    /**
     * Grant izin (global için companyId NULL bırak)
     */
    public static function assignPermission(int $userId, string $permissionCode, ?int $companyId = null, ?int $grantedBy = null, ?string $note = null, ?string $expiresAt = null): bool
    {
        self::initCrud($userId);

        // Aynı izin revoke edilmişse önce revoke kaydını silelim (temizlik)
        self::$crud->query(
            "DELETE FROM user_permissions WHERE user_id = :uid AND permission_code = :code AND IFNULL(company_id,0) = IFNULL(:cid,0) AND action = 'revoke'",
            ['uid' => $userId, 'code' => $permissionCode, 'cid' => $companyId]
        );

        // Grant var mı?
        $exists = self::$crud->read('user_permissions', [
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'company_id' => $companyId,
            'action' => 'grant'
        ], false);

        if ($exists) return true;

        return self::$crud->create('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'grant',
            'granted_by'      => $grantedBy,
            'note'            => $note,
            'expires_at'      => $expiresAt
        ]) !== false;
    }

    /**
     * Revoke izin (rol ile geleni de etkisiz bırakır)
     */
    public static function revokePermission(int $userId, string $permissionCode, ?int $companyId = null, ?int $grantedBy = null, ?string $note = null): bool
    {
        self::initCrud($userId);

        // Zaten revoke varsa true dön
        $exists = self::$crud->read('user_permissions', [
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'company_id' => $companyId,
            'action' => 'revoke'
        ], false);
        if ($exists) return true;

        return self::$crud->create('user_permissions', [
            'user_id'         => $userId,
            'permission_code' => $permissionCode,
            'company_id'      => $companyId,
            'action'          => 'revoke',
            'granted_by'      => $grantedBy,
            'note'            => $note
        ]) !== false;
    }
}
