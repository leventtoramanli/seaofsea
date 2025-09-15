<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/log.php';

class PositionHandler
{
    // Router köprüleri (isimler aynı kalsın)
    public static function get_position_areas(array $p = []): array   { return self::areas($p); }
    public static function get_positions_by_area(array $p = []): array{ return self::byArea($p); }
    public static function get_permissions(array $p = []): array      { return self::getPermissions($p); }
    public static function update_permissions(array $p = []): array   { return self::updatePermissions($p); }
    public static function get_city(array $p = []): array             { return self::getCity($p); } // düzeltildi

    /**
     * Şehir listesi (değişmedi)
     */
    private static function getCity(array $p): array
    {
        $crud = new Crud(0, false);

        $q     = trim((string)($p['q'] ?? ''));
        $iso3  = strtoupper(trim((string)($p['iso3'] ?? '')));
        $page  = max(1, (int)($p['page'] ?? 1));
        $per   = max(1, min(200, (int)($p['perPage'] ?? 200)));
        $off   = ($page - 1) * $per;

        $where = []; $bind = [];
        if ($q !== '')    { $where[] = "city LIKE CONCAT('%', :q, '%')"; $bind[':q'] = $q; }
        if ($iso3 !== '') { $where[] = "UPPER(iso3) = :iso3";           $bind[':iso3'] = $iso3; }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $rows = $crud->query("
            SELECT id, city, iso3
            FROM cities
            $whereSql
            ORDER BY city ASC
            LIMIT $per OFFSET $off
        ", $bind) ?: [];

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

        $cnt = $crud->query("SELECT COUNT(*) AS c FROM cities $whereSql", $bind) ?: [];
        $total = (int)($cnt[0]['c'] ?? 0);

        return ['cities' => $cities, 'total' => $total, 'page' => $page, 'perPage' => $per];
    }

    /**
     * DB’den area → [departments] map’i üretir.
     * Örn: { "crew": ["operations","safety"], "agency": ["operations","finance","..."] }
     */
    private static function areas(array $p): array
    {
        $crud = new Crud(0, false);

        $rows = $crud->query("
            SELECT area, department
            FROM position_catalog
            WHERE status = 'active'
              AND COALESCE(area,'') <> ''
              AND COALESCE(department,'') <> ''
            GROUP BY area, department
            ORDER BY area ASC, department ASC
        ") ?: [];

        $out = [];
        foreach ($rows as $r) {
            $area = trim((string)$r['area']);
            $dep  = trim((string)$r['department']);
            if ($area === '' || $dep === '') continue;

            if (!isset($out[$area])) $out[$area] = [];
            if (!in_array($dep, $out[$area], true)) {
                $out[$area][] = $dep;
            }
        }

        return $out ?: [];
    }

    /**
     * Verilen area (ve opsiyonel department, q) için pozisyonları listeler.
     * Geri dönüş: items=[{id,code,name,sort,category(node_type),department,sub_department,area,node_type}, ...]
     */
    private static function byArea(array $p): array
    {
        // Auth::requireAuth(); // okuma için açık bırakılabilir
        $crud = new Crud(0, false);

        $area = trim((string)($p['area'] ?? ''));
        $dept = trim((string)($p['department'] ?? ''));
        $q    = trim((string)($p['q'] ?? ''));

        if ($area === '') {
            return ['items'=>[], 'total'=>0, 'message'=>'area_required'];
        }

        $where = ["area = :a", "status = 'active'"];
        $bind  = [':a' => $area];

        if ($dept !== '') {
            $where[] = "department = :d";
            $bind[':d'] = $dept;
        }

        if ($q !== '') {
            $where[] = "(
                name LIKE CONCAT('%', :q, '%')
                OR description LIKE CONCAT('%', :q, '%')
                OR code LIKE CONCAT('%', :q, '%')
                OR tags LIKE CONCAT('%', :q, '%')
                OR node_type LIKE CONCAT('%', :q, '%')
            )";
            $bind[':q'] = $q;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $crud->query("
            SELECT id, code, name, sort, area, department, sub_department, node_type
            FROM position_catalog
            $whereSql
            ORDER BY COALESCE(sort, 999999) ASC, name ASC
        ", $bind) ?: [];

        return [
            'items' => array_map(fn($r) => [
                'id'             => (int)$r['id'],
                'code'           => (string)$r['code'],
                'name'           => (string)$r['name'],
                'sort'           => isset($r['sort']) ? (int)$r['sort'] : null,
                // geri uyumluluk: category ≡ node_type
                'category'       => (string)$r['node_type'],
                'node_type'      => (string)$r['node_type'],
                'department'     => $r['department'] ?? null,
                'sub_department' => $r['sub_department'] ?? null,
                'area'           => $r['area'] ?? null,
            ], $rows),
            'total' => count($rows),
        ];
    }

    /**
     * Tek pozisyonun default permission set’ini okur (opsiyonel tablo).
     * position_permission_defaults yoksa "not_supported" döner.
     * Parametre: id (position_catalog.id) veya code (position_catalog.code)
     */
    private static function getPermissions(array $p): array
    {
        Auth::requireAuth();
        $crud = new Crud(0, false);

        // Tablo var mı?
        $exists = $crud->query("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'position_permission_defaults'
            LIMIT 1
        ");
        if (!$exists) {
            return ['permission_codes' => [], 'error' => 'not_supported'];
        }

        $pid  = (int)($p['position_id'] ?? $p['id'] ?? 0);
        $code = trim((string)($p['code'] ?? ''));

        if ($pid <= 0 && $code === '') {
            return ['permission_codes' => [], 'error' => 'position_id_or_code_required'];
        }

        if ($code === '' && $pid > 0) {
            $row = $crud->read('position_catalog', ['id'=>$pid], ['code','name'], false);
            if (!$row) return ['permission_codes'=>[], 'error'=>'not_found'];
            $code = (string)$row['code'];
        }

        $row = $crud->read(
            'position_permission_defaults',
            ['position_code' => $code],
            ['position_code','permission_codes'],
            false
        );

        $codes = [];
        if ($row && !empty($row['permission_codes'])) {
            $dec = json_decode((string)$row['permission_codes'], true);
            if (is_array($dec)) {
                $codes = array_values(array_unique(
                    array_filter(array_map('strval', $dec), fn($x) => trim($x) !== '')
                ));
            }
        }

        return [
            'code'             => $code,
            'permission_codes' => $codes,
        ];
    }

    /**
     * Tek pozisyonun default permission set’ini yazar (opsiyonel tablo).
     * Eğer tablo yoksa not_supported döner.
     */
    private static function updatePermissions(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        // Tablo var mı?
        $exists = $crud->query("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'position_permission_defaults'
            LIMIT 1
        ");
        if (!$exists) {
            return ['updated' => false, 'error' => 'not_supported'];
        }

        $pid   = (int)($p['position_id'] ?? $p['id'] ?? 0);
        $code  = trim((string)($p['code'] ?? ''));
        $codes = $p['permission_codes'] ?? null;

        if ($pid <= 0 && $code === '') return ['updated'=>false, 'error'=>'position_id_or_code_required'];
        if (!is_array($codes))         return ['updated'=>false, 'error'=>'permission_codes_must_be_array'];

        // Yetki kontrolü (global admin veya özel izin)
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

        if ($code === '' && $pid > 0) {
            $row = $crud->read('position_catalog', ['id'=>$pid], ['code'], false);
            if (!$row) return ['updated'=>false, 'error'=>'not_found'];
            $code = (string)$row['code'];
        }

        // Temizlik
        $codes = array_values(array_unique(
            array_filter(
                array_map(fn($x) => trim((string)$x), $codes),
                fn($x) => $x !== ''
            )
        ));

        // UPSERT: önce UPDATE, etkilemediyse INSERT
        $okUpd = $crud->update(
            'position_permission_defaults',
            ['permission_codes' => json_encode($codes, JSON_UNESCAPED_UNICODE)],
            ['position_code' => $code]
        );

        if (!$okUpd) {
            // INSERT dene
            $insOk = $crud->create('position_permission_defaults', [
                'position_code'    => $code,
                'permission_codes' => json_encode($codes, JSON_UNESCAPED_UNICODE),
                'updated_by'       => $userId ?? null,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            if (!$insOk) {
                return ['updated'=>false, 'error'=>'db_update_failed'];
            }
        }

        return [
            'updated'          => true,
            'code'             => $code,
            'permission_codes' => $codes,
        ];
    }

    /**
     * Listeleme (yeni şema: position_catalog)
     */
    public static function get_list(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $area = isset($p['area']) ? (string)$p['area'] : null;
        $dept = isset($p['department']) ? (string)$p['department'] : null;
        $q    = isset($p['q']) ? trim((string)$p['q']) : '';

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = min(1000, max(1, (int)($p['per_page'] ?? 100)));
        $offset  = ($page - 1) * $perPage;

        $where = ["status = 'active'"];
        $bind  = [];

        if ($area) { $where[] = 'area = :area'; $bind[':area'] = $area; }
        if ($dept) { $where[] = 'department = :dept'; $bind[':dept'] = $dept; }
        if ($q !== '') {
            $where[] = "(name LIKE :q OR description LIKE :q OR code LIKE :q OR tags LIKE :q OR node_type LIKE :q)";
            $bind[':q'] = '%'.$q.'%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $limit = (int)$perPage;
        $off   = (int)$offset;

        $sql = "
            SELECT SQL_CALC_FOUND_ROWS
                   id, code, area, department, sub_department,
                   name, description, node_type, sort, status
            FROM position_catalog
            $whereSql
            ORDER BY COALESCE(sort, 999999), department, name
            LIMIT $limit OFFSET $off
        ";

        $rows  = $crud->query($sql, $bind) ?: [];
        $total = (int)($crud->query('SELECT FOUND_ROWS() AS t')[0]['t'] ?? 0);

        // Geri uyumluluk: category=node_type
        $items = array_map(function($r) {
            return [
                'id'             => (int)$r['id'],
                'code'           => (string)$r['code'],
                'area'           => (string)$r['area'],
                'department'     => $r['department'] ?? null,
                'sub_department' => $r['sub_department'] ?? null,
                'name'           => (string)$r['name'],
                'description'    => $r['description'] ?? null,
                'category'       => (string)$r['node_type'],
                'node_type'      => (string)$r['node_type'],
                'sort'           => isset($r['sort']) ? (int)$r['sort'] : null,
                'status'         => (string)$r['status'],
            ];
        }, $rows);

        Logger::info($total);

        return [
            'success' => true,
            'message' => 'OK',
            'data' => [
                'items'    => $items,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
            ],
            'code' => 200,
        ];
    }

    /**
     * Detay (yeni şema: position_catalog)
     */
    public static function detail(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) {
            return ['success'=>false, 'message'=>'id required', 'code'=>422];
        }

        $cols = ['id','code','area','department','sub_department','node_type','name','description','sort','status','parent_id','tags','created_at','updated_at'];
        $rows = $crud->read('position_catalog', ['id'=>$id], $cols, true);

        if (!$rows) {
            return ['success'=>false, 'message'=>'Not found', 'code'=>404];
        }

        // Geri uyumluluk: category=node_type
        $row = $rows[0];
        $row['category'] = $row['node_type'];

        return ['success'=>true, 'message'=>'OK', 'data'=>$row, 'code'=>200];
    }
}
