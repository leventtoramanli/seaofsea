<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class PositionHandler
{
    // Router köprüleri (isimler aynı kalsın)
    public static function get_position_areas(array $p=[]): array      { return self::areas($p); }
    public static function get_positions_by_area(array $p=[]): array   { return self::byArea($p); }
    public static function get_permissions(array $p = []): array       { return self::getPermissions($p); }
    public static function update_permissions(array $p = []): array    { return self::updatePermissions($p); }
    public static function get_city(array $p = []): array              { return self::getcity($p); }

    /**
     * DB’den area → [departments] map’i üretir.
     * JSON çıktısı: { "crew": ["operations","safety",...], "office": ["admin","hr",...] }
     */
    private static function getCity(array $p): array
    {
        // Okuma açık (auth gerekmiyor)
        $crud = new Crud(0, false);

        $q     = trim((string)($p['q'] ?? ''));
        $iso3  = strtoupper(trim((string)($p['iso3'] ?? '')));
        $page  = max(1, (int)($p['page'] ?? 1));
        $per   = max(1, min(200, (int)($p['perPage'] ?? 200)));
        $off   = ($page - 1) * $per;

        $where = []; $bind = [];
        if ($q !== '')    { $where[] = "name LIKE CONCAT('%', :q, '%')"; $bind[':q'] = $q; }
        if ($iso3 !== '') { $where[] = "UPPER(iso3) = :iso3";           $bind[':iso3'] = $iso3; }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $rows = $crud->query("
            SELECT id, name, iso3
            FROM cities
            $whereSql
            ORDER BY name ASC
            LIMIT $per OFFSET $off
        ", $bind) ?: [];

        // Title Case normalize
        $cities = array_map(function($r) {
            $name = (string)$r['name'];
            if (function_exists('mb_convert_case')) {
                $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            } else {
                $name = ucwords(strtolower($name));
            }
            return [
                'id'   => (int)$r['id'],
                'name' => $name,
                'iso3' => strtoupper((string)$r['iso3']),
            ];
        }, $rows);

        // total
        $cnt = $crud->query("SELECT COUNT(*) AS c FROM cities $whereSql", $bind) ?: [];
        $total = (int)($cnt[0]['c'] ?? 0);

        // Router genelde data içine sarıyor; çıplak döndürmek de OK
        return ['cities' => $cities, 'total' => $total, 'page' => $page, 'perPage' => $per];
    }


    private static function areas(array $p): array
    {
        // İstersen burayı açık bırakabilirsin; sadece okuma
        // Auth::requireAuth();

        $crud = new Crud(0, false); // guard kapalı, okuma
        $rows = $crud->query("
            SELECT area, department
            FROM company_positions
            WHERE COALESCE(area,'') <> ''
            GROUP BY area, department
            ORDER BY area ASC, department ASC
        ") ?: [];

        $out = [];
        foreach ($rows as $r) {
            $area = trim((string)$r['area']);
            $dep  = trim((string)($r['department'] ?? ''));
            if ($area === '') continue;

            if (!isset($out[$area])) $out[$area] = [];
            if ($dep !== '' && !in_array($dep, $out[$area], true)) {
                $out[$area][] = $dep;
            }
        }

        // Eğer bazı alanların hiç departmanı yoksa boş liste döndür
        if (!$out) $out = [];

        // Router tipik olarak bunu data içine saracak; çıplak map döndürmek OK
        return $out;
    }

    /**
     * Verilen area (ve opsiyonel department, q) için pozisyonları listeler.
     * Geri dönüş: items=[{id,name,sort,category,department,area}, ...]
     */
    private static function byArea(array $p): array
    {
        // Auth::requireAuth(); // okuma; istersen açık bırak
        $crud = new Crud(0, false);

        $area = trim((string)($p['area'] ?? ''));
        $dept = trim((string)($p['department'] ?? ''));
        $q    = trim((string)($p['q'] ?? '')); // arama opsiyonel

        if ($area === '') {
            return ['items'=>[], 'total'=>0, 'message'=>'area_required'];
        }

        $where = ["area = :a"];
        $bind  = [':a' => $area];

        if ($dept !== '') {
            $where[] = "department = :d";
            $bind[':d'] = $dept;
        }

        if ($q !== '') {
            $where[] = "(name LIKE CONCAT('%', :q, '%') OR category LIKE CONCAT('%', :q, '%'))";
            $bind[':q'] = $q;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $crud->query("
            SELECT id, name, sort, category, department, area
            FROM company_positions
            $whereSql
            ORDER BY COALESCE(sort, 999999) ASC, name ASC
        ", $bind) ?: [];

        return [
            'items' => array_map(fn($r) => [
                'id'         => (int)$r['id'],
                'name'       => (string)$r['name'],
                'sort'       => isset($r['sort']) ? (int)$r['sort'] : null,
                'category'   => $r['category'] ?? null,
                'department' => $r['department'] ?? null,
                'area'       => $r['area'] ?? null,
            ], $rows),
            'total' => count($rows),
        ];
    }

    /**
     * Tek pozisyonun permission_codes alanını okur.
     */
    private static function getPermissions(array $p): array
    {
        Auth::requireAuth();

        $pid = (int)($p['position_id'] ?? $p['id'] ?? 0);
        if ($pid <= 0) {
            return ['permission_codes' => [], 'error' => 'position_id_required'];
        }

        $crud = new Crud(0, false);
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
                $codes = array_values(array_unique(
                    array_filter(
                        array_map(fn($x) => trim((string)$x), $dec),
                        fn($x) => $x !== ''
                    )
                ));
            }
        }

        return [
            'id'               => (int)$row['id'],
            'name'             => (string)$row['name'],
            'permission_codes' => $codes,
        ];
    }

    /**
     * Tek pozisyonun permission_codes alanını günceller (JSON array).
     * Yetki: global admin veya PermissionService::hasPermission(user, 'position.update')
     */
    private static function updatePermissions(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $pid   = (int)($p['position_id'] ?? $p['id'] ?? 0);
        $codes = $p['permission_codes'] ?? null;

        if ($pid <= 0)            return ['updated' => false, 'error' => 'position_id_required'];
        if (!is_array($codes))    return ['updated' => false, 'error' => 'permission_codes_must_be_array'];

        // Yetki kontrolü
        $isGlobalAdmin = (bool)$crud->query("
            SELECT 1
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = :u AND r.scope = 'global' AND r.name = 'admin'
            LIMIT 1
        ", [':u' => $userId]);

        $hasCustomPerm = false;
        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            $hasCustomPerm = PermissionService::hasPermission($userId, 'position.update');
        }
        if (!($isGlobalAdmin || $hasCustomPerm)) {
            return ['updated' => false, 'error' => 'not_authorized'];
        }

        // Temizlik
        $codes = array_values(array_unique(
            array_filter(
                array_map(fn($x) => trim((string)$x), $codes),
                fn($x) => $x !== ''
            )
        ));

        // İstersen burada sadece scope='company' olan geçerli kodları süzebilirsin:
        // $valid = $crud->read('permissions', ['scope'=>'company', 'code' => ['IN', $codes]], ['code'], true) ?: [];
        // $codes = array_values(array_intersect($codes, array_map(fn($r)=>(string)$r['code'], $valid)));

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
