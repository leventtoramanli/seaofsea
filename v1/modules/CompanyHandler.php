<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';


/**
 * CompanyHandler
 * Router: call_user_func([CompanyHandler::class, $action], $params)
 */
class CompanyHandler
{
    /* ===== Router action köprüleri (statik) ===== */
    public static function list(array $p = []): array
    {
        return self::listCompanies($p);
    }
    public static function my_list(array $p = []): array
    {
        return self::myList($p);
    }
    public static function create(array $p = []): array
    {
        return self::createCompany($p);
    }
    public static function update(array $p = []): array
    {
        return self::updateCompany($p);
    }
    public static function archive(array $p = []): array
    {
        return self::archiveCompany($p);
    }
    public static function delete_soft(array $p = []): array
    {
        return self::archiveCompany($p);
    }
    public static function follow(array $p = []): array
    {
        return self::followCompany($p);
    }
    public static function unfollow(array $p = []): array
    {
        return self::unfollowCompany($p);
    }
    public static function members_list(array $p = []): array
    {
        return self::membersList($p);
    }
    public static function add_member(array $p = []): array
    {
        return self::addMember($p);
    }
    public static function upload_logo(array $p = []): array
    {
        return self::uploadLogo($p);
    }
    public static function detail(array $p = []): array
    {
        return self::detailCompany($p);
    }
    public static function my_role(array $p = []): array
    {
        return self::myRole($p);
    }
    public static function types(array $p = []): array
    {
        return self::typesList($p);
    }
    // legacy / flutter tarafıyla uyumlu alias'lar
    public static function get_company_detail(array $p = []): array
    {
        return self::detailCompany($p);
    }
    public static function get_user_company_role(array $p = []): array
    {
        return self::myRole($p);
    }
    public static function get_company_types(array $p = []): array
    {
        return self::typesList($p);
    }

    public static function get_company_users(array $p = []): array
    {
        return self::legacyGetCompanyUsers($p);
    } // {data:{data:[...]}} şekli
    public static function get_company_employees(array $p = []): array
    {
        return self::flatMembers($p);
    }           // düz liste

    public static function get_companies(array $p = []): array
    {
        return self::listCompanies(self::remapListParams($p));
    }
    public static function get_user_companies(array $p = []): array
    {
        return self::legacyMyCompanies($p);
    }

    public static function update_company(array $p = []): array
    {
        return self::updateCompany(self::remapUpdateParams($p));
    }
    public static function create_user_company(array $p = []): array
    {
        return self::addMember($p);
    }

    public static function get_company_followers(array $p = []): array
    {
        return self::followersList($p);
    }
    public static function follow_status(array $p = []): array
    {
        return self::followStatus($p);
    }

    public static function set_member_position(array $p = []): array
    {
        return self::setMemberPosition($p);
    }
    public static function sync_member_position_perms(array $p = []): array
    {
        return self::syncMemberPositionPerms($p);
    } // opsiyonel manuel senk

    public static function set_member_role(array $p = []): array
    {
        return self::setMemberRole($p);
    }
    public static function roles_list(array $p = []): array
    {
        return self::rolesList($p);
    }
    public static function sync_member_role_perms(array $p = []): array
    {
        return self::syncMemberRolePerms($p);
    }

    /* ================== LIST ================== */
    private static function listCompanies(array $p): array
    {
        $crud = new Crud(); // public liste için auth zorunlu değil
        $q = isset($p['q']) ? trim((string) $p['q']) : null;
        $active = array_key_exists('active', $p) ? (string) $p['active'] : null;
        // typeIds array veya csv gelebilir
        $typeCsv = null;
        if (isset($p['typeIds'])) {
            if (is_array($p['typeIds'])) {
                $typeCsv = implode(',', array_map('intval', $p['typeIds']));
            } else {
                $typeCsv = preg_replace('/\s+/', '', (string) $p['typeIds']);
            }
            if ($typeCsv === '')
                $typeCsv = null;
        }
        $page = max(1, (int) ($p['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($p['perPage'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $limitSql = (int) $perPage;
        $offsetSql = (int) $offset;

        // total
        $cnt = $crud->query("
            SELECT COUNT(*) total
            FROM companies c
            WHERE (:q IS NULL OR c.name LIKE CONCAT('%', :q, '%'))
              AND (:a IS NULL OR c.active = :a)
              AND c.visibility = 'visible'
              AND c.deleted_at IS NULL
              AND (
                :tc IS NULL OR EXISTS (
                  SELECT 1 FROM company_company_types x
                  WHERE x.company_id = c.id AND FIND_IN_SET(x.company_type_id, :tc)
                )
              )
        ", [':q' => $q !== null ? $q : null, ':a' => $active !== null ? $active : null, ':tc' => $typeCsv]);
        $total = (int) ($cnt[0]['total'] ?? 0);

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
            WHERE (:q IS NULL OR c.name LIKE CONCAT('%', :q, '%'))
              AND (:a IS NULL OR c.active = :a)
              AND c.visibility = 'visible'
              AND c.deleted_at IS NULL
              AND (
                :tc IS NULL OR EXISTS (
                  SELECT 1 FROM company_company_types x
                  WHERE x.company_id = c.id AND FIND_IN_SET(x.company_type_id, :tc)
                )
              )
            GROUP BY c.id
            ORDER BY c.name ASC
            LIMIT $limitSql OFFSET $offsetSql
        ", [
            ':q' => $q !== null ? $q : null,
            ':a' => $active !== null ? $active : null,
            ':tc' => $typeCsv,
        ]);

        if (!is_array($rows)) {
            $rows = [];
        }

        // CSV -> array çevir
        foreach ($rows as &$r) {
            $r['type_ids'] = self::csvToIntArray($r['type_ids_csv'] ?? null);
            $r['type_names'] = self::csvToStrArray($r['type_names_csv'] ?? null);
            unset($r['type_ids_csv'], $r['type_names_csv']);
        }

        return [
            'items' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total
        ];
    }

    // CompanyHandler sınıfı içine (private helper)
    private static function canCompany(int $actorUserId, int $companyId, string $permCode): bool
    {
        $crud = new Crud($actorUserId);

        // Kurucu / company-admin baypası (mevcut davranış)
        $c = $crud->read('companies', ['id' => $companyId], ['created_by'], false);
        $isCreator = $c && (int) ($c['created_by'] ?? 0) === $actorUserId;

        $isAdmin = (bool) $crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            AND r.scope='company' AND r.name='admin'
            LIMIT 1
        ", [':u' => $actorUserId, ':c' => $companyId]);

        if ($isCreator || $isAdmin)
            return true;

        // Gate (RBAC + user grants/revokes) — şu an B-4 öncesi ek katman
        return Gate::allows($permCode, $companyId);
    }

    /* ================== MY LIST ================== */
    private static function myList(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $page = max(1, (int) ($p['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($p['perPage'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $limitSql = (int) $perPage;
        $offsetSql = (int) $offset;

        // Toplam: Üye olduğu VEYA kurucusu olduğu şirketler (distinct)
        $cnt = $crud->query("
            SELECT COUNT(DISTINCT c.id) AS total
            FROM companies c
            LEFT JOIN company_users cu
            ON cu.company_id = c.id AND cu.user_id = :u
            WHERE (cu.user_id = :u OR c.created_by = :u)
        ", [':u' => $userId]);
        $total = (int) ($cnt[0]['total'] ?? 0);

        // Liste: Üyeyse gerçek rol; değil ama kurucusuysa 'admin' farz et
        $rows = $crud->query("
            SELECT
            c.id AS company_id,
            c.name,
            c.logo,
            c.created_at,
            COALESCE(cu.role_id, r_admin.id) AS role_id,
            COALESCE(r.name,
                    CASE WHEN cu.user_id IS NULL AND c.created_by = :u THEN 'admin' END
            ) AS role,
            CASE WHEN cu.user_id IS NULL AND c.created_by = :u THEN 'approved' ELSE cu.status END AS status,
            CASE WHEN cu.user_id IS NULL AND c.created_by = :u THEN 1         ELSE cu.is_active END AS is_active,
            CASE WHEN cf.user_id IS NULL THEN 0 ELSE 1 END AS is_follower
            FROM companies c
            LEFT JOIN company_users cu
                ON cu.company_id = c.id AND cu.user_id = :u
            LEFT JOIN roles r
                ON r.id = cu.role_id
            LEFT JOIN roles r_admin
                ON r_admin.scope = 'company' AND r_admin.name = 'admin'
            LEFT JOIN company_followers cf
                ON cf.company_id = c.id
                AND cf.user_id   = :u
                AND cf.unfollow IS NULL
            WHERE (cu.user_id = :u OR c.created_by = :u)
            GROUP BY c.id
            -- Admin'i üste al (üye olarak admin veya kurucu)
            ORDER BY
            CASE
                WHEN (cu.user_id IS NOT NULL AND r.name = 'admin')
                OR (cu.user_id IS NULL AND c.created_by = :u)
                THEN 1 ELSE 0
            END DESC,
            c.name ASC
            LIMIT $limitSql OFFSET $offsetSql
        ", [':u' => $userId]);

        if (!is_array($rows)) {
            $rows = [];
        }

        return [
            'items' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
        ];
    }

    /* ================== CREATE ================== */
    private static function createCompany(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $name = trim((string) ($p['name'] ?? ''));
        $email = trim((string) ($p['email'] ?? ''));
        if ($name === '' || $email === '') {
            // Router success saracak, standart payload döndürelim
            return ['created' => false, 'error' => 'name and email are required'];
        }

        $data = [
            'name' => $name,
            'email' => $email,
            'logo' => isset($p['logo']) ? (string) $p['logo'] : null,
            'active' => isset($p['active']) ? (int) $p['active'] : 1,
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'contact_info' => isset($p['contact_info']) ? json_encode($p['contact_info'], JSON_UNESCAPED_UNICODE) : null,
        ];
        // NULL değerleri kolonlardan temizle (Crud insert için)
        $data = array_filter($data, fn($v) => $v !== null);

        $cid = $crud->create('companies', $data);
        if (!$cid) {
            return ['created' => false, 'error' => 'create_failed'];
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
                    [':c' => $cid, ':t' => $tid]
                );
            }
        }

        // kurucuyu admin yap (varsa)
        $rid = self::findCompanyRoleId($crud, 'admin');
        $crud->query("
            INSERT INTO company_users (user_id, company_id, role_id, status, is_active, created_at)
            VALUES (:u,:c,:r,'approved',1, NOW())
            ON DUPLICATE KEY UPDATE role_id=VALUES(role_id), status='approved', is_active=1
        ", [':u' => $userId, ':c' => $cid, ':r' => $rid]);

        return ['created' => true, 'id' => (int) $cid];
    }

    /* ================== UPDATE ================== */
    private static function updateCompany(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['id'] ?? 0);
        if ($cid <= 0)
            return ['updated' => false, 'error' => 'id_required'];

        $c = $crud->read('companies', ['id' => $cid], false);
        if (!$c)
            return ['updated' => false, 'error' => 'not_found'];

        // 🔒 Gate + baypas
        if (!self::canCompany($userId, $cid, 'company.update')) {
            return ['updated' => false, 'error' => 'not_authorized'];
        }

        $update = [];
        if (array_key_exists('name', $p))
            $update['name'] = (string) $p['name'];
        if (array_key_exists('email', $p))
            $update['email'] = (string) $p['email'];
        if (array_key_exists('logo', $p))
            $update['logo'] = (string) $p['logo'];
        if (array_key_exists('active', $p))
            $update['active'] = (int) $p['active'];
        if (array_key_exists('contact_info', $p)) {
            $update['contact_info'] = is_array($p['contact_info'])
                ? json_encode($p['contact_info'], JSON_UNESCAPED_UNICODE)
                : (string) $p['contact_info'];
        }
        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            $crud->update('companies', $update, ['id' => $cid]);
        }
        if (array_key_exists('hide_staff_names_to_applicant', $p)) {
            $update['hide_staff_names_to_applicant'] =
                (int) ((bool) $p['hide_staff_names_to_applicant']);
        }
        if (array_key_exists('type_ids', $p)) {
            $crud->delete('company_company_types', ['company_id' => $cid]);
            $typeIds = is_array($p['type_ids']) ? array_map('intval', $p['type_ids']) : [];
            foreach ($typeIds as $tid) {
                $crud->query(
                    "INSERT IGNORE INTO company_company_types (company_id, company_type_id) VALUES (:c,:t)",
                    [':c' => $cid, ':t' => $tid]
                );
            }
        }

        return ['updated' => true, 'id' => $cid];
    }

    public static function hide(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['id'] ?? $p['company_id'] ?? 0);
        if ($cid <= 0)
            return ['success' => false, 'message' => 'id_required'];

        $c = $crud->read('companies', ['id' => $cid], false);
        if (!$c || !empty($c['deleted_at']))
            return ['success' => false, 'message' => 'not_found'];

        // 🔒 Gate + baypas
        if (!self::canCompany($userId, $cid, 'company.update')) {
            return ['success' => false, 'message' => 'not_authorized'];
        }

        $ok = $crud->update('companies', [
            'visibility' => 'hidden',
            'hidden_at' => date('Y-m-d H:i:s'),
            'hidden_by' => $userId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $cid]);

        return ['success' => (bool) $ok];
    }

    public static function unhide(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['id'] ?? $p['company_id'] ?? 0);
        if ($cid <= 0)
            return ['success' => false, 'message' => 'id_required'];

        $c = $crud->read('companies', ['id' => $cid], false);
        if (!$c || !empty($c['deleted_at']))
            return ['success' => false, 'message' => 'not_found'];

        // 🔒 Gate + baypas
        if (!self::canCompany($userId, $cid, 'company.update')) {
            return ['success' => false, 'message' => 'not_authorized'];
        }

        $ok = $crud->update('companies', [
            'visibility' => 'visible',
            'hidden_at' => null,
            'hidden_by' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $cid]);

        return ['success' => (bool) $ok];
    }

    /* ================== DELETE ================== */
    /* ================== ARCHIVE (soft delete) ================== */
    private static function archiveCompany(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['id'] ?? ($p['company_id'] ?? 0));
        if ($cid <= 0)
            return ['deleted' => false, 'error' => 'id_required'];

        $company = $crud->read('companies', ['id' => $cid], false);
        if (!$company)
            return ['deleted' => false, 'error' => 'not_found'];

        // 🔒 Gate + baypas
        if (!self::canCompany($userId, $cid, 'company.delete')) {
            return ['deleted' => false, 'error' => 'not_authorized'];
        }

        $ok = $crud->update('companies', [
            'active' => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $cid]);

        return ['deleted' => (bool) $ok];
    }

    public static function delete(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['id'] ?? $p['company_id'] ?? 0);
        $phrase = trim((string) ($p['confirm_phrase'] ?? ''));
        $password = (string) ($p['password'] ?? '');

        if ($cid <= 0)
            return ['success' => false, 'message' => 'id_required'];

        $c = $crud->read('companies', ['id' => $cid], false);
        if (!$c || !empty($c['deleted_at']))
            return ['success' => false, 'message' => 'not_found_or_already_deleted'];

        // 🔒 Gate (global ya da company-scope politikan) — hard delete daha sıkı
        if (!Gate::allows('company.delete.hard', $cid)) {
            return ['success' => false, 'message' => 'not_authorized'];
        }

        $expected = 'DELETE ' . $c['name'];
        if ($phrase !== $expected) {
            return ['success' => false, 'message' => 'confirm_phrase_mismatch'];
        }

        $me = $crud->read('users', ['id' => $userId], false);
        if (!$me || empty($me['password']) || !password_verify($password, (string) $me['password'])) {
            return ['success' => false, 'message' => 'password_invalid'];
        }

        $ok = $crud->update('companies', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $userId,
            'visibility' => 'hidden',
            'updated_at' => date('Y-m-d H:i:s'),
            'active' => 0,
        ], ['id' => $cid]);

        return ['success' => (bool) $ok];
    }

    private static function addMember(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $companyId = (int) ($p['company_id'] ?? 0);
        $rank = trim((string) ($p['rank'] ?? ''));
        $roleName = strtolower(trim((string) ($p['role'] ?? 'viewer')));

        if ($companyId <= 0) {
            return ['ok' => false, 'error' => 'company_id_required'];
        }

        // role_id bul (scope=company)
        $roleId = self::findCompanyRoleId($crud, $roleName);

        // uniq key: uq_company_users(company_id,user_id)
        $sql = "
            INSERT INTO company_users (user_id, company_id, role_id, rank, status, is_active, created_at, updated_at)
            VALUES (:u, :c, :r, :rank, 'approved', 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                role_id = VALUES(role_id),
                rank = VALUES(rank),
                status = 'approved',
                is_active = 1,
                updated_at = NOW()
        ";
        $ok = $crud->query($sql, [':u' => $userId, ':c' => $companyId, ':r' => $roleId, ':rank' => $rank]) !== false;
        if ($ok) {
            if (isset($p['position_id'])) {
                $pid = (int) $p['position_id'];
                self::applyPositionDefaults($crud, $userId /*actor*/ , $userId /*target self*/ , $companyId, $pid);
            }
        }
        return ['ok' => (bool) $ok];
    }

    private static function uploadLogo(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $companyId = (int) ($p['company_id'] ?? 0);
        if ($companyId <= 0) {
            return ['success' => false, 'error' => 'company_id_required'];
        }

        $c = $crud->read('companies', ['id' => $companyId], false);
        if (!$c)
            return ['success' => false, 'error' => 'not_found'];

        if (!self::canCompany($userId, $companyId, 'company.update')) {
            return ['success' => false, 'error' => 'not_authorized'];
        }

        $isCreator = (int) ($c['created_by'] ?? 0) === $userId;
        $isAdmin = (bool) $crud->query("
            SELECT 1 FROM company_users cu
            JOIN roles r ON r.id=cu.role_id
            WHERE cu.user_id=:u AND cu.company_id=:c
            AND r.scope='company' AND r.name='admin' LIMIT 1
        ", [':u' => $userId, ':c' => $companyId]);

        $hasPerm = PermissionService::hasPermission($userId, 'company.update', $companyId);
        if (!($isCreator || $isAdmin || $hasPerm)) {
            return ['success' => false, 'error' => 'not_authorized'];
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'no_file_uploaded'];
        }

        // (Opsiyonel) Yetki sıkılaştırmak istersen burada company admin/owner kontrolü yapabilirsin.

        require_once __DIR__ . '/FileHandler.php';
        $fh = new FileHandler();

        // uploads/images/companies/logo/ altına yaz
        $upload = $fh->upload($_FILES['file'], [
            'allowedTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'folder' => 'images/companies/logo',
            'prefix' => 'c_' . $companyId . '_',
            'resize' => true,
            'addWatermark' => (bool) ($p['addWatermark'] ?? false),
            'maxWidth' => 1920,
            'maxHeight' => 1920,
        ]);

        if (!($upload['success'] ?? false)) {
            return ['success' => false, 'error' => $upload['error'] ?? 'upload_failed'];
        }

        $filename = $upload['filename'] ?? null;
        if (!$filename) {
            return ['success' => false, 'error' => 'filename_missing'];
        }

        // DB update
        $ok = $crud->update('companies', ['logo' => $filename, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $companyId]);
        if (!$ok) {
            // DB başarısızsa dosyayı geri al
            $fh->delete('images/companies/logo/' . $filename);
            return ['success' => false, 'error' => 'db_update_failed'];
        }

        return [
            'success' => true,
            'file_name' => $filename,
            'url' => $upload['url'] ?? null,
        ];
    }

    /* ================== FOLLOW / UNFOLLOW ================== */
    private static function followCompany(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['company_id'] ?? 0);
        if ($cid <= 0)
            return ['followed' => false, 'error' => 'company_id_required'];

        $crud->query("
            INSERT INTO company_followers (company_id, user_id, created_at, unfollow)
            VALUES (:c, :u, NOW(), NULL)
            ON DUPLICATE KEY UPDATE unfollow=NULL
        ", [':c' => $cid, ':u' => $userId]);

        return ['followed' => true];
    }

    private static function unfollowCompany(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['company_id'] ?? 0);
        if ($cid <= 0)
            return ['unfollowed' => false, 'error' => 'company_id_required'];

        $res = $crud->query("
            UPDATE company_followers SET unfollow=NOW()
            WHERE company_id=:c AND user_id=:u AND unfollow IS NULL
        ", [':c' => $cid, ':u' => $userId]);

        // Crud->query UPDATE döndürürken fetchAll kullanmadığı için dönüşü kontrol edemiyoruz; bilgi amaçlı sabit true döndürelim
        return ['unfollowed' => true];
    }

    /* ================== MEMBERS LIST ================== */
    private static function membersList(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        // — Paging'i en başta hesapla; hata dönüşlerinde de aynısını kullanalım
        $page = max(1, (int) ($p['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($p['perPage'] ?? 25)));
        $fail = function (string $err) use ($page, $perPage) {
            return ['items' => [], 'page' => $page, 'perPage' => $perPage, 'total' => 0, 'error' => $err];
        };

        // — Zorunlu parametre
        $companyId = (int) ($p['company_id'] ?? 0);
        if ($companyId <= 0) {
            return $fail('company_id_required');
        }

        // — Şirket var mı?
        $c = $crud->read('companies', ['id' => $companyId], false);
        if (!$c)
            return $fail('not_found');

        // — Yetki: kurucu || company-admin || explicit permission || (opsiyonel) Gate::allows
        $isCreator = (int) ($c['created_by'] ?? 0) === $userId;
        $isAdmin = (bool) $crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u
            AND cu.company_id = :c
            AND r.scope = 'company'
            AND r.name  = 'admin'
            LIMIT 1
        ", [':u' => $userId, ':c' => $companyId]);

        $hasPerm = PermissionService::hasPermission($userId, 'company.members.view', $companyId);
        $gateAllows = class_exists('Gate') && method_exists('Gate', 'allows')
            ? Gate::allows('company.members.view', $companyId)
            : false;

        if (!($isCreator || $isAdmin || $hasPerm || $gateAllows)) {
            return $fail('not_authorized');
        }

        // — Filtreler (status / role_id)
        $status = $p['status'] ?? null; // pending / approved / preapproved / rejected / wmApproval
        if (is_string($status)) {
            $s = strtolower(preg_replace('/[\s_\-]/', '', $status));
            if ($s === 'waitingmanagerapproval' || $s === 'wmapproval') {
                $status = 'wmApproval';
            } elseif ($s === 'preapproved') {
                $status = 'preapproved';
            } elseif ($s === 'approved') {
                $status = 'approved';
            } elseif ($s === 'rejected') {
                $status = 'rejected';
            } elseif ($s === 'pending') {
                $status = 'pending';
            } else {
                $status = null; // tanınmıyorsa filtreleme yapma
            }
        }

        $roleId = isset($p['role_id']) ? (int) $p['role_id'] : null;

        // — Limit/Offset
        $offset = ($page - 1) * $perPage;
        $limitSql = (int) $perPage;
        $offsetSql = (int) $offset;

        // — Toplam
        $cnt = $crud->query("
            SELECT COUNT(*) AS total
            FROM company_users cu
            WHERE cu.company_id = :c
            AND (:s IS NULL OR cu.status = :s)
            AND (:r IS NULL OR cu.role_id = :r)
        ", [':c' => $companyId, ':s' => $status !== '' ? $status : null, ':r' => $roleId]);
        $total = (int) ($cnt[0]['total'] ?? 0);

        // — Liste
        $rows = $crud->query("
            SELECT
                cu.id AS company_user_id,
                cu.user_id,
                u.name, u.surname, u.email, u.user_image,
                cu.role_id, r.name AS role,
                cu.position_id, p.name AS position_name, cu.custom_position_name,
                cu.rank,
                cu.approvalF, cu.approvalS,
                af.name AS approvalF_name, af.surname AS approvalF_surname,
                asu.name AS approvalS_name, asu.surname AS approvalS_surname,
                cu.status, cu.is_active, cu.created_at
            FROM company_users cu
            JOIN users u            ON u.id = cu.user_id
            LEFT JOIN roles r       ON r.id = cu.role_id
            LEFT JOIN company_positions p ON p.id = cu.position_id
            LEFT JOIN users af      ON af.id = cu.approvalF
            LEFT JOIN users asu     ON asu.id = cu.approvalS
            WHERE cu.company_id = :c
            AND (:s IS NULL OR cu.status = :s)
            AND (:r IS NULL OR cu.role_id = :r)
            ORDER BY u.name, u.surname
            LIMIT $limitSql OFFSET $offsetSql
        ", [':c' => $companyId, ':s' => $status !== '' ? $status : null, ':r' => $roleId]);

        return [
            'items' => $rows ?: [],
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total
        ];
    }

    /* ============= helpers ============= */

    private static function csvToIntArray(?string $csv): array
    {
        if (!$csv)
            return [];
        $out = [];
        foreach (explode(',', $csv) as $s) {
            $s = trim($s);
            if ($s === '')
                continue;
            $out[] = (int) $s;
        }
        return $out;
    }

    private static function csvToStrArray(?string $csv): array
    {
        if (!$csv)
            return [];
        $out = [];
        foreach (explode(',', $csv) as $s) {
            $s = trim($s);
            if ($s === '')
                continue;
            $out[] = $s;
        }
        return $out;
    }

    private static function findCompanyRoleId(Crud $crud, string $name): ?int
    {
        $row = $crud->read('roles', ['scope' => 'company', 'name' => $name], ['id'], false);
        return $row ? (int) $row['id'] : null;
    }

    /* ================== DETAIL ================== */
    private static function detailCompany(array $p): array
    {
        $crud = new Crud(); // public erişim
        $cid = (int) ($p['id'] ?? $p['company_id'] ?? 0);
        if ($cid <= 0)
            return ['found' => false, 'error' => 'id_required'];

        $rows = $crud->query("
            SELECT
            c.*,
            GROUP_CONCAT(DISTINCT cct.company_type_id ORDER BY cct.company_type_id) AS type_ids_csv,
            GROUP_CONCAT(DISTINCT ct.name ORDER BY ct.name) AS type_names_csv,
            (SELECT COUNT(*) FROM company_users cu
                WHERE cu.company_id=c.id AND cu.status='approved' AND cu.is_active=1) AS member_count,
            (SELECT COUNT(*) FROM company_followers f
                WHERE f.company_id=c.id AND f.unfollow IS NULL) AS follower_count
            FROM companies c
            LEFT JOIN company_company_types cct ON cct.company_id = c.id
            LEFT JOIN company_types ct ON ct.id = cct.company_type_id
            WHERE c.id = :id
            GROUP BY c.id
            LIMIT 1
        ", [':id' => $cid]);

        if (!$rows)
            return ['found' => false];

        $r = $rows[0];

        // --- erişim kuralları ---
        if (!empty($r['deleted_at'])) {
            return ['found' => false];
        }

        $auth = Auth::check();
        $uid = $auth ? (int) $auth['user_id'] : null;
        $isCreator = $uid && (int) ($r['created_by'] ?? 0) === $uid;

        $isAdmin = false;
        if ($uid) {
            $isAdmin = (bool) $crud->query("
                SELECT 1
                FROM company_users cu
                JOIN roles r ON r.id = cu.role_id
                WHERE cu.user_id = :u AND cu.company_id = :c
                AND r.scope='company' AND r.name='admin'
                LIMIT 1
            ", [':u' => $uid, ':c' => $cid]);
        }

        if (($r['visibility'] ?? 'visible') === 'hidden' && !($isCreator || $isAdmin)) {
            return ['found' => false];
        }

        // --- mevcut dönüş şekli ---
        $r['type_ids'] = self::csvToIntArray($r['type_ids_csv'] ?? null);
        $r['type_names'] = self::csvToStrArray($r['type_names_csv'] ?? null);
        unset($r['type_ids_csv'], $r['type_names_csv']);

        $r['contact_info_json'] = $r['contact_info'];
        if (!empty($r['contact_info'])) {
            $decoded = json_decode($r['contact_info'], true);
            if (is_array($decoded)) {
                $r['contact_info'] = $decoded;
            }
        }

        return $r;
    }

    /* ================== MY ROLE ================== */
    private static function myRole(array $p): array
    {
        // Opsiyonel kimlik: token yoksa 'none' dön
        $auth = Auth::check();
        if (!$auth) {
            return ['role' => 'none'];
        }
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['company_id'] ?? $p['id'] ?? 0);
        if ($cid <= 0)
            return ['role' => 'none'];

        $c = $crud->read('companies', ['id' => $cid], ['created_by'], false);
        if ($c && (int) ($c['created_by'] ?? 0) === $userId) {
            return ['role' => 'admin'];
        }

        $row = $crud->query("
            SELECT cu.role_id, r.name AS role
            FROM company_users cu
            LEFT JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            LIMIT 1
        ", [':u' => $userId, ':c' => $cid]);

        if ($row && !empty($row[0]['role'])) {
            return ['role' => (string) $row[0]['role']];
        }

        $f = $crud->query("
            SELECT 1 FROM company_followers
            WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
            LIMIT 1
        ", [':c' => $cid, ':u' => $userId]);

        return $f ? ['role' => 'follower'] : ['role' => 'none'];
    }

    /* ================== TYPES ================== */
    private static function typesList(array $p): array
    {
        $crud = new Crud();

        if (!empty($p['filter_ids'])) {
            $ids = is_array($p['filter_ids']) ? array_map('intval', $p['filter_ids']) : [];
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $rows = $crud->query(
                    "SELECT id, category, name, description
                    FROM company_types
                    WHERE id IN ($in)
                    ORDER BY name ASC",
                    $ids
                );
                return $rows ?: [];
            }
        }

        $rows = $crud->query("
            SELECT id, category, name, description
            FROM company_types
            ORDER BY name ASC
        ");
        return $rows ?: [];
    }
    /* ---- legacy param remap ---- */
    private static function remapListParams(array $p): array
    {
        if (isset($p['search']) && !isset($p['q']))
            $p['q'] = trim((string) $p['search']);
        if (isset($p['limit']) && !isset($p['perPage']))
            $p['perPage'] = (int) $p['limit'];
        if (isset($p['type_ids']) && !isset($p['typeIds']))
            $p['typeIds'] = $p['type_ids'];
        return $p;
    }

    private static function remapUpdateParams(array $p): array
    {
        if (!isset($p['id']) && isset($p['company_id']))
            $p['id'] = (int) $p['company_id'];
        if (isset($p['company_type_ids']) && !isset($p['type_ids']))
            $p['type_ids'] = $p['company_type_ids'];
        return $p;
    }

    /* ---- followers (modalde 'data' bekleniyor) ---- */
    private static function followersList(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $cid = (int) ($p['company_id'] ?? $p['id'] ?? 0);
        if ($cid <= 0)
            return ['data' => []];

        $rows = $crud->query("
            SELECT u.id, u.name, u.surname, u.email, u.user_image
            FROM company_followers f
            JOIN users u ON u.id = f.user_id
            WHERE f.company_id = :c AND f.unfollow IS NULL
            ORDER BY u.name, u.surname
        ", [':c' => $cid]);

        return ['data' => $rows ?: []];
    }

    /* ---- get_company_users: eski ekran {data:{data:[...]}} bekliyor ---- */
    private static function legacyGetCompanyUsers(array $p): array
    {
        $base = self::membersList($p); // ['items'=>..., 'page'=>..., 'perPage'=>..., 'total'=>...]
        return [
            // outer 'data' zaten router tarafından sarılıyor, burada inner shape:
            'data' => $base['items'] ?? [],
            'page' => $base['page'] ?? 1,
            'perPage' => $base['perPage'] ?? 25,
            'total' => $base['total'] ?? 0,
        ];
    }

    /* ---- get_company_employees: düz liste bekleyen sayfa için ---- */
    private static function flatMembers(array $p): array
    {
        $base = self::membersList($p);
        return $base['items'] ?? [];
    }

    /* ---- get_user_companies: eski ad + id alanı düzeltilmiş düz liste ---- */
    private static function legacyMyCompanies(array $p): array
    {
        $res = self::myList($p); // items[] içinde company_id var
        $out = [];
        foreach (($res['items'] ?? []) as $row) {
            $out[] = [
                'id' => (int) ($row['company_id'] ?? $row['id'] ?? 0),
                'name' => $row['name'] ?? '',
                'role' => $row['role'] ?? null,
                'status' => $row['status'] ?? null,
                'rank' => $row['rank'] ?? null,
                'logo' => $row['logo'] ?? null,
            ];
        }
        return $out;
    }
    private static function followStatus(array $p): array
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['user_id'];
        $crud = new Crud($userId);

        $cid = (int) ($p['company_id'] ?? 0);
        if ($cid <= 0) {
            return ['is_follower' => false, 'follower_count' => 0, 'error' => 'company_id_required'];
        }

        // Takip ediyor mu?
        $isFollower = (bool) $crud->query("
            SELECT 1
            FROM company_followers
            WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
            LIMIT 1
        ", [':c' => $cid, ':u' => $userId]);

        // Aktif takipçi sayısı
        $cnt = $crud->query("
            SELECT COUNT(*) AS total
            FROM company_followers
            WHERE company_id = :c AND unfollow IS NULL
        ", [':c' => $cid]);
        $total = (int) ($cnt[0]['total'] ?? 0);

        return ['is_follower' => $isFollower, 'follower_count' => $total];
        /*istemci çağrısı
        {
        "module": "company",
        "action": "follow_status",
        "params": { "company_id": 123, "device_uuid": "<uuid>" }
        }
        { "is_follower": true, "follower_count": 42 }
        */
    }
    private static function setMemberPosition(array $p): array
    {
        $auth = Auth::requireAuth();
        $actor = (int) $auth['user_id'];
        $crud = new Crud($actor);

        $companyId = (int) ($p['company_id'] ?? 0);
        $targetUser = (int) ($p['user_id'] ?? 0);
        $positionId = isset($p['position_id']) ? (int) $p['position_id'] : null;
        $customName = isset($p['custom_position_name']) ? trim((string) $p['custom_position_name']) : null;

        if ($companyId <= 0 || $targetUser <= 0) {
            return ['updated' => false, 'error' => 'company_id_and_user_id_required'];
        }

        // 🔒 Gate + baypas
        if (!self::canCompany($actor, $companyId, 'company.members.update')) {
            return ['updated' => false, 'error' => 'not_authorized'];
        }

        $c = $crud->read('companies', ['id' => $companyId], ['id'], false);
        if (!$c)
            return ['updated' => false, 'error' => 'company_not_found'];

        $cu = $crud->read(
            'company_users',
            ['company_id' => $companyId, 'user_id' => $targetUser],
            ['id', 'position_id', 'custom_position_name', 'status', 'is_active'],
            false
        );
        if (!$cu)
            return ['updated' => false, 'error' => 'member_not_found'];

        if ($positionId !== null) {
            $pos = $crud->read('company_positions', ['id' => $positionId], ['id', 'name'], false);
            if (!$pos)
                return ['updated' => false, 'error' => 'position_not_found'];
        }

        $upd = ['updated_at' => date('Y-m-d H:i:s'), 'position_id' => $positionId];
        if ($customName !== null)
            $upd['custom_position_name'] = $customName;

        $ok = $crud->update('company_users', $upd, ['company_id' => $companyId, 'user_id' => $targetUser]);
        if (!$ok)
            return ['updated' => false, 'error' => 'db_update_failed'];

        $sync = self::applyPositionDefaults($crud, $actor, $targetUser, $companyId, $positionId);
        return ['updated' => true, 'sync' => $sync];
    }
    /* Manuel tetik (ör: pozisyon default izinleri değişti → herkese yeniden uygula) */
    private static function syncMemberPositionPerms(array $p): array
    {
        $auth = Auth::requireAuth();
        $actor = (int) $auth['user_id'];
        $crud = new Crud($actor);

        $companyId = (int) ($p['company_id'] ?? 0);
        $targetUser = (int) ($p['user_id'] ?? 0);
        if ($companyId <= 0 || $targetUser <= 0) {
            return ['synced' => false, 'error' => 'company_id_and_user_id_required'];
        }

        // 🔒
        if (!self::canCompany($actor, $companyId, 'company.members.update')) {
            return ['synced' => false, 'error' => 'not_authorized'];
        }

        $cu = $crud->read('company_users', ['company_id' => $companyId, 'user_id' => $targetUser], ['position_id'], false);
        if (!$cu)
            return ['synced' => false, 'error' => 'member_not_found'];

        $positionId = isset($cu['position_id']) ? (int) $cu['position_id'] : null;
        $sync = self::applyPositionDefaults($crud, $actor, $targetUser, $companyId, $positionId);

        return ['synced' => true, 'sync' => $sync];
    }
    private static function applyPositionDefaults(Crud $crud, int $actorId, int $targetUserId, int $companyId, ?int $positionId): array
    {
        // 1) Eski position-default grantlarını temizle
        $crud->query("
            DELETE FROM user_permissions
            WHERE user_id = :u AND (company_id = :c OR (company_id IS NULL AND :c = 0))
            AND note LIKE 'pos:%'
        ", [':u' => $targetUserId, ':c' => $companyId]);

        if ($positionId === null) {
            return ['applied' => 0, 'removed' => 0];
        }

        // 2) Pozisyondan kodları çek
        $row = $crud->read('company_positions', ['id' => $positionId], ['permission_codes'], false);
        $codes = [];
        if ($row && !empty($row['permission_codes'])) {
            $dec = json_decode((string) $row['permission_codes'], true);
            if (is_array($dec)) {
                $codes = array_values(array_unique(
                    array_filter(array_map(fn($x) => trim((string) $x), $dec), fn($x) => $x !== '')
                ));
            }
        }
        if (!$codes)
            return ['applied' => 0, 'removed' => 0];

        // 3) Kodları permissions tablosuna göre doğrula (FK hatasını önle)
        //    IN(:c0,:c1,...) için dinamik yer tutucu
        $ph = [];
        $map = [];
        foreach ($codes as $i => $code) {
            $k = ":p$i";
            $ph[] = $k;
            $map[$k] = $code;
        }
        $valid = $crud->query("
            SELECT code FROM permissions WHERE code IN (" . implode(',', $ph) . ")
        ", $map) ?: [];
        $validCodes = array_map(fn($r) => (string) $r['code'], $valid);
        if (!$validCodes)
            return ['applied' => 0, 'removed' => 0];

        // 4) INSERT IGNORE ile GRANT ekle (not: pos:<id>)
        $applied = 0;
        foreach ($validCodes as $code) {
            $ok = $crud->query("
                INSERT IGNORE INTO user_permissions
                (user_id, company_id, permission_code, action, granted_by, note, created_at)
                VALUES
                (:u, :c, :code, 'grant', :g, :note, NOW())
            ", [
                ':u' => $targetUserId,
                ':c' => $companyId,
                ':code' => $code,
                ':g' => $actorId,
                ':note' => 'pos:' . $positionId
            ]) !== false;
            if ($ok)
                $applied++;
        }

        // removed sayısını ölçmek için istersen DELETE row count’ı ayrı ölçebilirsin;
        // Crud::query fetchAll döndürdüğü için burada 0 geçiyoruz.
        return ['applied' => $applied, 'removed' => 0];
    }
    private static function rolesList(array $p): array
    {
        $crud = new Crud(); // public okuyabilir
        $rows = $crud->read('roles', ['scope' => 'company'], ['id', 'sort', 'name', 'description'], true, ['sort' => 'ASC', 'name' => 'ASC']);
        return ['items' => $rows ?: []];
    }
    private static function setMemberRole(array $p): array
    {
        $auth = Auth::requireAuth();
        $actor = (int) $auth['user_id'];
        $crud = new Crud($actor);

        $companyId = (int) ($p['company_id'] ?? 0);
        $targetId = (int) ($p['user_id'] ?? 0);
        $roleId = isset($p['role_id']) ? (int) $p['role_id'] : null;
        $roleName = isset($p['role']) ? strtolower(trim((string) $p['role'])) : null;
        $seedRolePerms = (int) ($p['seed_role_permissions'] ?? 0) === 1;
        $resyncPosition = (int) ($p['resync_position_perms'] ?? 0) === 1;

        if ($companyId <= 0 || $targetId <= 0) {
            return ['updated' => false, 'error' => 'company_id_and_user_id_required'];
        }

        // 🔒
        if (!self::canCompany($actor, $companyId, 'company.roles.update')) {
            return ['updated' => false, 'error' => 'not_authorized'];
        }

        // ... (mevcut gövden değişmiyor: member_exists/role resolve/last admin guard/seed)
        // (Burada senin mevcut kodun aynen kalıyor — sadece yetki kısmını yukarıda Gate’e taşıdık.)
        // ↓↓↓ Aşağıdaki orijinal gövdeni bırakıyoruz (kısaltılmış)
        $cu = $crud->read(
            'company_users',
            ['company_id' => $companyId, 'user_id' => $targetId],
            ['id', 'role_id', 'position_id', 'status', 'is_active'],
            false
        );
        if (!$cu)
            return ['updated' => false, 'error' => 'member_not_found'];

        if ($roleId === null && $roleName !== null) {
            $row = $crud->read('roles', ['scope' => 'company', 'name' => $roleName], ['id'], false);
            if (!$row)
                return ['updated' => false, 'error' => 'role_not_found'];
            $roleId = (int) $row['id'];
        }
        if ($roleId === null)
            return ['updated' => false, 'error' => 'role_id_or_name_required'];

        $targetIsAdmin = (bool) $crud->query("
            SELECT 1 FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.company_id = :c AND cu.user_id = :u
            AND r.scope='company' AND r.name='admin'
            LIMIT 1
        ", [':c' => $companyId, ':u' => $targetId]);

        if ($targetIsAdmin) {
            $adminCntRow = $crud->query("
                SELECT COUNT(*) AS cnt
                FROM company_users cu
                JOIN roles r ON r.id = cu.role_id
                WHERE cu.company_id = :c
                AND cu.status='approved' AND cu.is_active=1
                AND r.scope='company' AND r.name='admin'
            ", [':c' => $companyId]);
            $adminCnt = (int) ($adminCntRow[0]['cnt'] ?? 0);
            if ($adminCnt <= 1) {
                return ['updated' => false, 'error' => 'cannot_remove_last_admin'];
            }
        }

        $ok = $crud->update('company_users', [
            'role_id' => $roleId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['company_id' => $companyId, 'user_id' => $targetId]);

        if (!$ok)
            return ['updated' => false, 'error' => 'db_update_failed'];

        $sync = ['role_seed' => ['applied' => 0, 'removed' => 0], 'position_seed' => null];
        if ($seedRolePerms) {
            $sync['role_seed'] = self::applyRoleDefaults($crud, $actor, $targetId, $companyId, $roleId);
        }
        if ($resyncPosition) {
            $pid = isset($cu['position_id']) ? (int) $cu['position_id'] : null;
            $sync['position_seed'] = self::applyPositionDefaults($crud, $actor, $targetId, $companyId, $pid);
        }

        return ['updated' => true, 'sync' => $sync];
    }
    private static function syncMemberRolePerms(array $p): array
    {
        $auth = Auth::requireAuth();
        $actor = (int) $auth['user_id'];
        $crud = new Crud($actor);

        $companyId = (int) ($p['company_id'] ?? 0);
        $userId = (int) ($p['user_id'] ?? 0);
        if ($companyId <= 0 || $userId <= 0)
            return ['synced' => false, 'error' => 'company_id_and_user_id_required'];

        // 🔒
        if (!self::canCompany($actor, $companyId, 'company.roles.update')) {
            return ['synced' => false, 'error' => 'not_authorized'];
        }

        $cu = $crud->read('company_users', ['company_id' => $companyId, 'user_id' => $userId], ['role_id'], false);
        if (!$cu || empty($cu['role_id']))
            return ['synced' => false, 'error' => 'role_not_assigned'];

        $sync = self::applyRoleDefaults($crud, $actor, $userId, $companyId, (int) $cu['role_id']);
        return ['synced' => true, 'sync' => $sync];
    }
    private static function applyRoleDefaults(Crud $crud, int $actorId, int $userId, int $companyId, int $roleId): array
    {
        // 1) Eski role-seed’leri temizle
        $crud->query("
            DELETE FROM user_permissions
            WHERE user_id=:u AND (company_id=:c OR (company_id IS NULL AND :c=0))
            AND note LIKE 'role:%'
        ", [':u' => $userId, ':c' => $companyId]);

        // 2) Bu role atanmış permission_code’ları çek
        $r = $crud->query("
            SELECT rp.permission_code
            FROM role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            WHERE rp.role_id = :rid AND r.scope='company'
        ", [':rid' => $roleId]) ?: [];
        if (!$r)
            return ['applied' => 0, 'removed' => 0];

        $codes = array_values(array_unique(array_filter(array_map(fn($x) => (string) $x['permission_code'], $r))));
        if (!$codes)
            return ['applied' => 0, 'removed' => 0];

        // 3) Güvenlik: permissions tablosunda var mı?
        $ph = [];
        $map = [];
        foreach ($codes as $i => $code) {
            $k = ":p$i";
            $ph[] = $k;
            $map[$k] = $code;
        }
        $valid = $crud->query("SELECT code FROM permissions WHERE code IN(" . implode(',', $ph) . ")", $map) ?: [];
        $validCodes = array_map(fn($x) => (string) $x['code'], $valid);
        if (!$validCodes)
            return ['applied' => 0, 'removed' => 0];

        // 4) INSERT IGNORE ile seed
        $applied = 0;
        foreach ($validCodes as $code) {
            $ok = $crud->query("
                INSERT IGNORE INTO user_permissions
                (user_id, company_id, permission_code, action, granted_by, note, created_at)
                VALUES (:u,:c,:code,'grant',:g,:note, NOW())
            ", [
                ':u' => $userId,
                ':c' => $companyId,
                ':code' => $code,
                ':g' => $actorId,
                ':note' => 'role:' . $roleId
            ]) !== false;
            if ($ok)
                $applied++;
        }

        return ['applied' => $applied, 'removed' => 0];
    }
}
