<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class PositionHandler
{
    public static function get_position_areas(array $p=[]): array { return self::areas($p); }
    public static function get_positions_by_area(array $p=[]): array { return self::byArea($p); }
    public static function get_permissions(array $p = []): array { return self::getPermissions($p); }
    public static function update_permissions(array $p = []): array { return self::updatePermissions($p); }

    private static function areas(array $p): array
    {
        // UI map<String, List<String>> bekliyor
        return [
            'Ship'   => ['Deck', 'Engine', 'Catering'],
            'Office' => ['HR', 'Operations', 'IT', 'Finance'],
        ];
    }

    private static function byArea(array $p): array
    {
        $area = trim((string)($p['area'] ?? ''));
        $map = [
            'Deck'      => ['Captain','Chief Officer','Officer','Deckhand'],
            'Engine'    => ['Chief Engineer','2nd Engineer','Motorman'],
            'Catering'  => ['Cook','Steward'],
            'HR'        => ['HR Specialist','Recruiter'],
            'Operations'=> ['Coordinator','Supervisor'],
            'IT'        => ['SysAdmin','Developer'],
            'Finance'   => ['Accountant','Controller'],
        ];
        $list = $map[$area] ?? [];
        return array_map(fn($n)=>['name'=>$n], $list);
    }
    private static function getPermissions(array $p): array
    {
        // Okuma için auth zorunlu yapmadım; istersen Auth::requireAuth();
        $auth = Auth::requireAuth();
        $pid = (int)($p['position_id'] ?? $p['id'] ?? 0);
        if ($pid <= 0) {
            return ['permission_codes' => [], 'error' => 'position_id_required'];
        }

        $crud = new Crud(); // read anonim de olabilir; RBAC varsa Crud kendisi kontrol eder
        $row = $crud->read(
            'company_positions',
            ['id' => $pid],
            ['id','name','permission_codes'],
            false
        );
        if (!$row) {
            return ['permission_codes' => [], 'error' => 'not_found'];
        }

        $codes = [];
        if (!empty($row['permission_codes'])) {
            $dec = json_decode((string)$row['permission_codes'], true);
            if (is_array($dec)) {
                // temizle, uniq’le, indexleri sıfırla
                $codes = array_values(array_unique(
                    array_filter(
                        array_map(fn($x) => trim((string)$x), $dec),
                        fn($x) => $x !== ''
                    )
                ));
            }
        }

        return [
            'id'                => (int)$row['id'],
            'name'              => (string)$row['name'],
            'permission_codes'  => $codes,
        ];
    }

    private static function updatePermissions(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $pid   = (int)($p['position_id'] ?? $p['id'] ?? 0);
        $codes = $p['permission_codes'] ?? null;

        if ($pid <= 0)            return ['updated' => false, 'error' => 'position_id_required'];
        if (!is_array($codes))    return ['updated' => false, 'error' => 'permission_codes_must_be_array'];

        // --- Yetki kontrolü ---
        // 1) Global admin mi? (users.role_id → roles.scope='global' AND name='admin')
        $isGlobalAdmin = (bool)$crud->query("
            SELECT 1
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :u AND r.scope = 'global' AND r.name = 'admin'
            LIMIT 1
        ", [':u' => $userId]);

        // 2) Ya da sistemde tanımlı özel izin var mı? (opsiyonel; varsa kullan)
        $hasCustomPerm = false;
        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            $hasCustomPerm = PermissionService::hasPermission($userId, 'position.update');
        }

        if (!($isGlobalAdmin || $hasCustomPerm)) {
            return ['updated' => false, 'error' => 'not_authorized'];
        }

        // --- Payload temizliği ---
        $codes = array_values(array_unique(
            array_filter(
                array_map(fn($x) => trim((string)$x), $codes),
                fn($x) => $x !== ''
            )
        ));

        // JSON olarak sakla
        $ok = $crud->update(
            'company_positions',
            ['permission_codes' => json_encode($codes, JSON_UNESCAPED_UNICODE)],
            ['id' => $pid]
        );

        if (!$ok) {
            return ['updated' => false, 'error' => 'db_update_failed'];
        }

        return [
            'updated'          => true,
            'id'               => $pid,
            'permission_codes' => $codes,
        ];
    }
}
