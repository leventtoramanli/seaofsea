<?php

require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';

class AuthService
{
    /** basit bir permission existence cache */
    private static array $permExistsCache = [];

    /**
     * Tablo-aksiyon seviyesinde kapı bekçisi.
     * Not: Crud içinden çağrılıyor → burada Crud'u permissionGuard=false ile kullanıyoruz
     * ki tekrar AuthService::can çağrısına girip döngü oluşmasın.
     */
    public static function can(?int $userId, string $action, string $table): bool
    {
        if (!$userId) return false;

        // Guard'ı kapat: bu sınıfta kontrol yapacağız
        $crud = new Crud($userId, false);

        // Kullanıcı durumu (blok/verify)
        $user = $crud->read('users', ['id' => $userId], ['id','role_id','is_verified','blocked_until'], false);
        if (!$user) return false;

        if (!empty($user['blocked_until']) && strtotime((string)$user['blocked_until']) > time()) {
            return false;
        }

        $isMutation = in_array(strtolower($action), ['create','update','delete'], true);
        if ($isMutation && (int)($user['is_verified'] ?? 0) !== 1) {
            // yazma işlemleri için doğrulanmış e-posta şartı
            return false;
        }

        // 1) Eğer permissions tablosunda "table.action" kodu tanımlıysa → onu uygula
        $code = strtolower($table . '.' . $action);
        if (self::permissionExists($crud, $code)) {
            // Global kapsamda user/role bazlı kontrol (companyId yok → NULL)
            return PermissionService::hasPermission((int)$userId, $code, null);
        }

        // 2) Kod tanımlı değilse (mevcut sisteminizi bozmamak için) makul fallback:
        switch (strtolower($action)) {
            case 'read':
            case 'advancedread':
                // Okuma tarafı zaten public_access_rules + handler seviyesinde kısıtlanıyor.
                return true;

            case 'create':
            case 'update':
            case 'delete':
                // Yazma için minimal koruma: global admin ise serbest, değilse kapalı.
                return self::isGlobalAdmin($crud, (int)($user['role_id'] ?? 0));

            default:
                return false;
        }
    }

    /** permissions.code var mı? (guard kapalı Crud ile, recursion yok) */
    private static function permissionExists(Crud $crud, string $code): bool
    {
        if (isset(self::$permExistsCache[$code])) {
            return self::$permExistsCache[$code];
        }
        $row = $crud->read('permissions', ['code' => $code], ['id'], false);
        $exists = (bool)$row;
        self::$permExistsCache[$code] = $exists;
        return $exists;
    }

    /** kullanıcının global admin olup olmadığını hızlı kontrol */
    private static function isGlobalAdmin(Crud $crud, ?int $roleId): bool
    {
        if (!$roleId) return false;
        $r = $crud->read('roles', ['id' => $roleId], ['scope','name'], false);
        return $r && ($r['scope'] ?? '') === 'global' && ($r['name'] ?? '') === 'admin';
    }
}
