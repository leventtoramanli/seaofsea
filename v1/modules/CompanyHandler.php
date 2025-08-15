<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

/**
 * CompanyHandler
 * Router: call_user_func([CompanyHandler::class, $action], $params)
 */
class CompanyHandler
{
    /* ===== Router action köprüleri (statik) ===== */
    public static function list(array $p = []): array          { return self::listCompanies($p); }
    public static function my_list(array $p = []): array       { return self::myList($p); }
    public static function create(array $p = []): array        { return self::createCompany($p); }
    public static function update(array $p = []): array        { return self::updateCompany($p); }
    public static function delete(array $p = []): array        { return self::deleteCompany($p); }
    public static function follow(array $p = []): array        { return self::followCompany($p); }
    public static function unfollow(array $p = []): array      { return self::unfollowCompany($p); }
    public static function members_list(array $p = []): array  { return self::membersList($p); }

    /* ================== LIST ================== */
    private static function listCompanies(array $p): array
    {
        $crud   = new Crud(); // public liste için auth zorunlu değil
        $q      = isset($p['q']) ? trim((string)$p['q']) : null;
        $active = array_key_exists('active', $p) ? (string)$p['active'] : null;
        // typeIds array veya csv gelebilir
        $typeCsv = null;
        if (isset($p['typeIds'])) {
            if (is_array($p['typeIds'])) {
                $typeCsv = implode(',', array_map('intval', $p['typeIds']));
            } else {
                $typeCsv = preg_replace('/\s+/', '', (string)$p['typeIds']);
            }
            if ($typeCsv === '') $typeCsv = null;
        }
        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        // total
        $cnt = $crud->query("
            SELECT COUNT(*) total
            FROM companies c
            WHERE (:q IS NULL OR c.name LIKE CONCAT(:q, '%'))
              AND (:a IS NULL OR c.active = :a)
              AND (
                :tc IS NULL OR EXISTS (
                  SELECT 1 FROM company_company_types x
                  WHERE x.company_id = c.id AND FIND_IN_SET(x.company_type_id, :tc)
                )
              )
        ", [':q'=>$q!==null?$q:null, ':a'=>$active!==null?$active:null, ':tc'=>$typeCsv]);
        $total = (int)($cnt[0]['total'] ?? 0);

        // list
        $rows = $crud->query("
            SELECT
              c.*,
              GROUP_CONCAT(DISTINCT cct.company_type_id ORDER BY cct.company_type_id) AS type_ids_csv,
              GROUP_CONCAT(DISTINCT ct.name ORDER BY ct.name) AS type_names_csv,
              (SELECT COUNT(*) FROM company_users cu
                 WHERE cu.company_id=c.id AND cu.status='approved' AND cu.is_active=1) AS member_count
            FROM companies c
            LEFT JOIN company_company_types cct ON cct.company_id=c.id
            LEFT JOIN company_types ct ON ct.id=cct.company_type_id
            WHERE (:q IS NULL OR c.name LIKE CONCAT(:q, '%'))
              AND (:a IS NULL OR c.active = :a)
              AND (
                :tc IS NULL OR EXISTS (
                  SELECT 1 FROM company_company_types x
                  WHERE x.company_id = c.id AND FIND_IN_SET(x.company_type_id, :tc)
                )
              )
            GROUP BY c.id
            ORDER BY c.name ASC
            LIMIT :limit OFFSET :offset
        ", [
            ':q'=>$q!==null?$q:null,
            ':a'=>$active!==null?$active:null,
            ':tc'=>$typeCsv,
            ':limit'=>$perPage,
            ':offset'=>$offset
        ]);

        // CSV -> array çevir
        foreach ($rows as &$r) {
            $r['type_ids']   = self::csvToIntArray($r['type_ids_csv'] ?? null);
            $r['type_names'] = self::csvToStrArray($r['type_names_csv'] ?? null);
            unset($r['type_ids_csv'], $r['type_names_csv']);
        }

        return [
            'items'   => $rows,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total
        ];
    }

    /* ================== MY LIST ================== */
    private static function myList(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $cnt = $crud->query("SELECT COUNT(*) total FROM company_users WHERE user_id = :u", [':u'=>$userId]);
        $total = (int)($cnt[0]['total'] ?? 0);

        $rows = $crud->query("
            SELECT
              c.id AS company_id, c.name,
              cu.role_id, r.name AS role,
              cu.status, cu.is_active,
              CASE WHEN cf.user_id IS NULL THEN 0 ELSE 1 END AS is_follower
            FROM companies c
            JOIN company_users cu ON cu.company_id=c.id AND cu.user_id=:u
            LEFT JOIN roles r ON r.id = cu.role_id
            LEFT JOIN company_followers cf
              ON cf.company_id=c.id AND cf.user_id=:u AND cf.unfollow IS NULL
            ORDER BY (r.name='admin') DESC, c.name ASC
            LIMIT :limit OFFSET :offset
        ", [':u'=>$userId, ':limit'=>$perPage, ':offset'=>$offset]);

        return ['items'=>$rows, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }

    /* ================== CREATE ================== */
    private static function createCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $name  = trim((string)($p['name'] ?? ''));
        $email = trim((string)($p['email'] ?? ''));
        if ($name === '' || $email === '') {
            // Router success saracak, standart payload döndürelim
            return ['created'=>false, 'error'=>'name and email are required'];
        }

        $data = [
            'name'        => $name,
            'email'       => $email,
            'logo'        => isset($p['logo']) ? (string)$p['logo'] : null,
            'active'      => isset($p['active']) ? (int)$p['active'] : 1,
            'created_by'  => $userId,
            'created_at'  => date('Y-m-d H:i:s'),
            'contact_info'=> isset($p['contact_info']) ? json_encode($p['contact_info'], JSON_UNESCAPED_UNICODE) : null,
        ];
        // NULL değerleri kolonlardan temizle (Crud insert için)
        $data = array_filter($data, fn($v)=>$v !== null);

        $cid = $crud->create('companies', $data);
        if (!$cid) {
            return ['created'=>false, 'error'=>'create_failed'];
        }

        // type_ids (junction)
        $typeIds = [];
        if (isset($p['type_ids'])) {
            $typeIds = is_array($p['type_ids']) ? array_map('intval', $p['type_ids']) : [];
        }
        if ($typeIds) {
            foreach ($typeIds as $tid) {
                $crud->query(
                    "INSERT IGNORE INTO company_company_types (company_id, company_type_id) VALUES (:c,:t)",
                    [':c'=>$cid, ':t'=>$tid]
                );
            }
        }

        // kurucuyu admin yap (varsa)
        $rid = self::findCompanyRoleId($crud, 'admin');
        $crud->query("
            INSERT INTO company_users (user_id, company_id, role_id, status, is_active, created_at)
            VALUES (:u,:c,:r,'approved',1, NOW())
            ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), status='approved', is_active=1
        ", [':u'=>$userId, ':c'=>$cid, ':r'=>$rid]);

        return ['created'=>true, 'id'=>(int)$cid];
    }

    /* ================== UPDATE ================== */
    private static function updateCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $cid = (int)($p['id'] ?? 0);
        if ($cid <= 0) return ['updated'=>false, 'error'=>'id_required'];

        $update = [];
        if (array_key_exists('name', $p))         $update['name'] = (string)$p['name'];
        if (array_key_exists('email', $p))        $update['email'] = (string)$p['email'];
        if (array_key_exists('logo', $p))         $update['logo'] = (string)$p['logo'];
        if (array_key_exists('active', $p))       $update['active'] = (int)$p['active'];
        if (array_key_exists('contact_info', $p)) $update['contact_info'] = is_array($p['contact_info'])
            ? json_encode($p['contact_info'], JSON_UNESCAPED_UNICODE) : (string)$p['contact_info'];
        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            $crud->update('companies', $update, ['id'=>$cid]);
        }

        if (array_key_exists('type_ids', $p)) {
            $crud->delete('company_company_types', ['company_id'=>$cid]);
            $typeIds = is_array($p['type_ids']) ? array_map('intval', $p['type_ids']) : [];
            foreach ($typeIds as $tid) {
                $crud->query(
                    "INSERT IGNORE INTO company_company_types (company_id, company_type_id) VALUES (:c,:t)",
                    [':c'=>$cid, ':t'=>$tid]
                );
            }
        }

        return ['updated'=>true, 'id'=>$cid];
    }

    /* ================== DELETE ================== */
    private static function deleteCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $cid = (int)($p['id'] ?? 0);
        if ($cid <= 0) return ['deleted'=>false, 'error'=>'id_required'];

        $ok = $crud->delete('companies', ['id'=>$cid]);
        return ['deleted'=>(bool)$ok];
    }

    /* ================== FOLLOW / UNFOLLOW ================== */
    private static function followCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $cid = (int)($p['company_id'] ?? 0);
        if ($cid <= 0) return ['followed'=>false, 'error'=>'company_id_required'];

        $crud->query("
            INSERT INTO company_followers (company_id, user_id, created_at, unfollow)
            VALUES (:c, :u, NOW(), NULL)
            ON DUPLICATE KEY UPDATE unfollow=NULL
        ", [':c'=>$cid, ':u'=>$userId]);

        return ['followed'=>true];
    }

    private static function unfollowCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $cid = (int)($p['company_id'] ?? 0);
        if ($cid <= 0) return ['unfollowed'=>false, 'error'=>'company_id_required'];

        $res = $crud->query("
            UPDATE company_followers SET unfollow=NOW()
            WHERE company_id=:c AND user_id=:u AND unfollow IS NULL
        ", [':c'=>$cid, ':u'=>$userId]);

        // Crud->query UPDATE döndürürken fetchAll kullanmadığı için dönüşü kontrol edemiyoruz; bilgi amaçlı sabit true döndürelim
        return ['unfollowed'=>true];
    }

    /* ================== MEMBERS LIST ================== */
    private static function membersList(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId <= 0) return ['items'=>[], 'page'=>1, 'perPage'=>25, 'total'=>0, 'error'=>'company_id_required'];

        $status = $p['status'] ?? null;          // pending/approved/...
        $roleId = isset($p['role_id']) ? (int)$p['role_id'] : null;

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $cnt = $crud->query("
            SELECT COUNT(*) total
            FROM company_users cu
            WHERE cu.company_id=:c
              AND (:s IS NULL OR cu.status=:s)
              AND (:r IS NULL OR cu.role_id=:r)
        ", [':c'=>$companyId, ':s'=>$status!==''?$status:null, ':r'=>$roleId]);

        $total = (int)($cnt[0]['total'] ?? 0);

        $rows = $crud->query("
            SELECT
              cu.user_id,
              u.name, u.surname, u.email, u.user_image,
              cu.role_id, r.name AS role,
              cu.position_id, p.name AS position_name, cu.custom_position_name,
              cu.status, cu.is_active, cu.created_at
            FROM company_users cu
            JOIN users u ON u.id = cu.user_id
            LEFT JOIN roles r ON r.id = cu.role_id
            LEFT JOIN company_positions p ON p.id = cu.position_id
            WHERE cu.company_id=:c
              AND (:s IS NULL OR cu.status=:s)
              AND (:r IS NULL OR cu.role_id=:r)
            ORDER BY u.name, u.surname
            LIMIT :limit OFFSET :offset
        ", [':c'=>$companyId, ':s'=>$status!==''?$status:null, ':r'=>$roleId, ':limit'=>$perPage, ':offset'=>$offset]);

        return ['items'=>$rows, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }

    /* ============= helpers ============= */

    private static function csvToIntArray(?string $csv): array
    {
        if (!$csv) return [];
        $out = [];
        foreach (explode(',', $csv) as $s) {
            $s = trim($s);
            if ($s === '') continue;
            $out[] = (int)$s;
        }
        return $out;
    }

    private static function csvToStrArray(?string $csv): array
    {
        if (!$csv) return [];
        $out = [];
        foreach (explode(',', $csv) as $s) {
            $s = trim($s);
            if ($s === '') continue;
            $out[] = $s;
        }
        return $out;
    }

    private static function findCompanyRoleId(Crud $crud, string $name): ?int
    {
        $row = $crud->read('roles', ['scope'=>'company', 'name'=>$name], ['id'], false);
        return $row ? (int)$row['id'] : null;
    }
}
