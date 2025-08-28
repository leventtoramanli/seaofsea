<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';

/**
 * Router: module=company_announcement
 * Class name Router ile uyumlu olmalı (ucfirst + '_Handler')
 */
class Company_announcementHandler
{
    /* ========= Router entry points ========= */
    public static function list(array $p = []): array         { return self::listAnnouncements($p); }
    public static function detail(array $p = []): array       { return self::detailAnnouncement($p); }
    public static function create(array $p = []): array       { return self::createAnnouncement($p); }
    public static function update(array $p = []): array       { return self::updateAnnouncement($p); }
    public static function set_status(array $p = []): array   { return self::setStatusAction($p); }
    public static function hide(array $p = []): array         { return self::setStatus($p, 'hidden'); }
    public static function unhide(array $p = []): array       { return self::setStatus($p, 'active', clearHidden:true); }
    public static function archive(array $p = []): array      { return self::setStatus($p, 'archived'); }
    public static function unarchive(array $p = []): array    { return self::setStatus($p, 'active', clearArchived:true); }
    public static function pin(array $p = []): array          { return self::setPinned($p, true); }
    public static function unpin(array $p = []): array        { return self::setPinned($p, false); }
    public static function delete(array $p = []): array       { return self::hardDelete($p); }

    /* ========= helpers ========= */

    private static function now(): string { return date('Y-m-d H:i:s'); }

    private static function normVisibility(?string $v): string {
        $v = strtolower((string)($v ?? 'public'));
        return in_array($v, ['public','followers','internal'], true) ? $v : 'public';
    }

    private static function normStatus(?string $s): string {
        $s = strtolower((string)($s ?? 'active'));
        return in_array($s, ['active','hidden','archived'], true) ? $s : 'active';
    }

    /** Kullanıcının şirket içi rolünü çöz (admin/editor/viewer/follower/none) */
    private static function roleFor(int $uid, int $cid, Crud $crud): string
    {
        // Kurucu → admin say
        $c = $crud->read('companies', ['id'=>$cid], ['created_by'], false);
        if ($c && (int)$c['created_by'] === $uid) return 'admin';

        // company_users’tan rol
        $r = $crud->query("
            SELECT r.name
            FROM company_users cu
            LEFT JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            LIMIT 1
        ", [':u'=>$uid, ':c'=>$cid]);
        if ($r && !empty($r[0]['name'])) return (string)$r[0]['name'];

        // takipçi mi?
        $f = $crud->query("
            SELECT 1 FROM company_followers
            WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
            LIMIT 1
        ", [':c'=>$cid, ':u'=>$uid]);

        return $f ? 'follower' : 'none';
    }

    private static function isEmployee(string $role): bool {
        return in_array($role, ['admin','editor','viewer'], true);
    }

    /** Kurucu veya admin/editor → tam yetki */
    private static function isOwnerOrAdminOrEditor(int $uid, int $cid, Crud $crud): bool
    {
        // kurucu
        $c = $crud->read('companies', ['id'=>$cid], ['created_by'], false);
        if ($c && (int)$c['created_by'] === $uid) return true;

        // admin/editor
        $hit = $crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
              AND r.scope='company' AND r.name IN ('admin','editor')
            LIMIT 1
        ", [':u'=>$uid, ':c'=>$cid]);

        return (bool)$hit;
    }

    /** Spesifik izin kodu (varsa) + admin/editor/kurucu fallback */
    private static function can(int $uid, int $cid, Crud $crud, string $permCode): bool
    {
        if (self::isOwnerOrAdminOrEditor($uid, $cid, $crud)) return true;
        // PermissionService varsa ve tanımlıysa
        try {
            return PermissionService::hasPermission($uid, $permCode, $cid);
        } catch (\Throwable $e) {
            // izin altyapısı yoksa sessizce fallback
            return false;
        }
    }

    /** Görünen kitleye göre SQL parçası */
    private static function visibleWhere(string $alias, string $viewer): string
    {
        if ($viewer === 'internal') {
            return " AND {$alias}.visibility IN ('public','followers','internal') ";
        } elseif ($viewer === 'followers') {
            return " AND {$alias}.visibility IN ('public','followers') ";
        }
        return " AND {$alias}.visibility = 'public' ";
    }

    /* ========= actions ========= */

    /** LIST */
    private static function listAnnouncements(array $p): array
    {
        $auth = Auth::check();
        $uid  = $auth ? (int)$auth['user_id'] : 0;
        $crud = new Crud($uid ?: null);

        $cid = (int)($p['company_id'] ?? 0);
        if ($cid <= 0) return ['items'=>[], 'page'=>1, 'perPage'=>10, 'total'=>0, 'error'=>'company_id_required'];

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(50, (int)($p['perPage'] ?? 10)));
        $offset  = ($page - 1) * $perPage;

        $role   = $uid ? self::roleFor($uid, $cid, $crud) : 'none';
        $isEmp  = self::isEmployee($role);
        $isFol  = ($role === 'follower');

        $visSql = " AND ( a.visibility='public' "
                . ($isFol||$isEmp ? " OR a.visibility='followers' " : "")
                . ($isEmp ? " OR a.visibility='internal' " : "")
                . " ) ";

        $includeHidden = $isEmp && (int)($p['include_hidden'] ?? 0) === 1;
        $statusSql = $includeHidden
            ? " AND a.status IN ('active','hidden') "
            : " AND a.status='active' ";

        $now = self::now();
        $timeSql = " AND ( (a.starts_at IS NULL OR a.starts_at <= :now) AND (a.ends_at IS NULL OR a.ends_at >= :now) ) ";

        $q    = isset($p['q']) ? trim((string)$p['q']) : '';
        $qSql = $q !== '' ? " AND (a.title LIKE CONCAT('%',:q,'%') OR a.body LIKE CONCAT('%',:q,'%')) " : '';

        // total
        $cnt = $crud->query("
            SELECT COUNT(*) AS total
            FROM company_announcements a
            WHERE a.company_id=:c
            $visSql
            $statusSql
            $timeSql
            $qSql
        ", [':c'=>$cid, ':now'=>$now] + ($q!==''? [':q'=>$q]:[]));
        $total = (int)($cnt[0]['total'] ?? 0);

        // ⚠️ LIMIT/OFFSET burada stringe gömülüyor (önceden cast edildi)
        $limitSql = " LIMIT $perPage OFFSET $offset ";

        $rows = $crud->query("
            SELECT
            a.id, a.company_id, a.author_user_id, a.visibility, a.status,
            a.pinned, a.title, a.body, a.meta, a.starts_at, a.ends_at,
            a.created_at, a.updated_at,
            u.name AS author_name, u.surname AS author_surname, u.user_image AS author_image
            FROM company_announcements a
            LEFT JOIN users u ON u.id = a.author_user_id
            WHERE a.company_id=:c
            $visSql
            $statusSql
            $timeSql
            $qSql
            ORDER BY a.pinned DESC, a.created_at DESC
            $limitSql
        ", [':c'=>$cid, ':now'=>$now] + ($q!==''? [':q'=>$q]:[]));

        if ($rows) {
            foreach ($rows as &$r) {
                if (!empty($r['meta'])) {
                    $m = json_decode((string)$r['meta'], true);
                    if (is_array($m)) $r['meta'] = $m;
                }
            }
        }

        return ['items'=>$rows ?: [], 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total, 'role'=>$role];
    }

    /** DETAIL */
    private static function detailAnnouncement(array $p): array
    {
        $auth = Auth::check();
        $uid  = $auth ? (int)$auth['user_id'] : 0;
        $crud = new Crud($uid ?: null);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['found'=>false, 'error'=>'id_required'];

        $row = $crud->read('company_announcements', ['id'=>$id], false);
        if (!$row) return ['found'=>false];

        $cid   = (int)$row['company_id'];
        $role  = $uid ? self::roleFor($uid, $cid, $crud) : 'none';
        $isEmp = self::isEmployee($role);
        $isFol = ($role === 'follower');

        // visibility
        if ($row['visibility'] === 'internal' && !$isEmp) return ['found'=>false];
        if ($row['visibility'] === 'followers' && !($isEmp || $isFol)) return ['found'=>false];

        // status + time window
        $now = self::now();
        $inWindow = (empty($row['starts_at']) || $row['starts_at'] <= $now)
                 && (empty($row['ends_at'])   || $row['ends_at']   >= $now);
        if (($row['status'] !== 'active') || !$inWindow) {
            if (!$isEmp) return ['found'=>false];
        }

        if (!empty($row['meta'])) {
            $m = json_decode((string)$row['meta'], true);
            if (is_array($m)) $row['meta'] = $m;
        }

        $author = null;
        if (!empty($row['author_user_id'])) {
            $author = $crud->read('users', ['id'=>(int)$row['author_user_id']], ['id','name','surname','user_image'], false);
        }

        return ['found'=>true, 'item'=>$row, 'author'=>$author, 'role'=>$role];
    }

    /** CREATE */
    private static function createAnnouncement(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $cid   = (int)($p['company_id'] ?? 0);
        $title = trim((string)($p['title'] ?? ''));

        if ($cid <= 0 || $title === '') {
            return ['created'=>false, 'error'=>'company_id_and_title_required'];
        }
        if (!self::can($actor, $cid, $crud, 'company.ann.create')) {
            return ['created'=>false, 'error'=>'not_authorized'];
        }

        $row = [
            'company_id'     => $cid,
            'author_user_id' => $actor,
            'visibility'     => self::normVisibility($p['visibility'] ?? null),
            'status'         => self::normStatus($p['status'] ?? 'active'),
            'pinned'         => (int)($p['pinned'] ?? 0),
            'title'          => $title,
            'body'           => array_key_exists('body', $p) ? (string)$p['body'] : null,
            'meta'           => array_key_exists('meta', $p)
                                ? (is_array($p['meta']) ? json_encode($p['meta'], JSON_UNESCAPED_UNICODE) : (string)$p['meta'])
                                : null,
            'starts_at'      => !empty($p['starts_at']) ? (string)$p['starts_at'] : null,
            'ends_at'        => !empty($p['ends_at'])   ? (string)$p['ends_at']   : null,
            'created_at'     => self::now(),
            'updated_at'     => self::now(),
        ];
        $row = array_filter($row, fn($v) => $v !== null);

        $id = $crud->create('company_announcements', $row);
        return $id ? ['created'=>true, 'id'=>(int)$id] : ['created'=>false, 'error'=>'db_insert_failed'];
    }

    /** UPDATE */
    private static function updateAnnouncement(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['updated'=>false, 'error'=>'id_required'];

        $a = $crud->read('company_announcements', ['id'=>$id], ['company_id'], false);
        if (!$a) return ['updated'=>false, 'error'=>'not_found'];

        $cid = (int)$a['company_id'];
        if (!self::can($actor, $cid, $crud, 'company.ann.update')) {
            return ['updated'=>false, 'error'=>'not_authorized'];
        }

        $upd = ['updated_at'=>self::now()];
        if (array_key_exists('title',$p))       $upd['title']      = (string)$p['title'];
        if (array_key_exists('body',$p))        $upd['body']       = (string)$p['body'];
        if (array_key_exists('visibility',$p))  $upd['visibility'] = self::normVisibility($p['visibility']);
        if (array_key_exists('status',$p))      $upd['status']     = self::normStatus($p['status']);
        if (array_key_exists('pinned',$p))      $upd['pinned']     = (int)$p['pinned'];
        if (array_key_exists('meta',$p))        $upd['meta']       = is_array($p['meta']) ? json_encode($p['meta'], JSON_UNESCAPED_UNICODE) : (string)$p['meta'];
        if (array_key_exists('starts_at',$p))   $upd['starts_at']  = $p['starts_at'] ? (string)$p['starts_at'] : null;
        if (array_key_exists('ends_at',$p))     $upd['ends_at']    = $p['ends_at']   ? (string)$p['ends_at']   : null;

        if (count($upd) === 1) { // sadece updated_at varsa
            return ['updated'=>false, 'error'=>'nothing_to_update'];
        }

        $ok = $crud->update('company_announcements', $upd, ['id'=>$id]);
        return ['updated'=>(bool)$ok];
    }

    /** SET STATUS (payload: id, status=active|hidden|archived) */
    private static function setStatusAction(array $p): array
    {
        $to = strtolower((string)($p['status'] ?? ''));
        if (!in_array($to, ['active','hidden','archived'], true)) {
            return ['success'=>false, 'error'=>'bad_status'];
        }
        return self::setStatus($p, $to, clearHidden: $to==='active', clearArchived: $to==='active');
    }

    private static function setStatus(array $p, string $to, bool $clearHidden=false, bool $clearArchived=false): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['success'=>false, 'error'=>'id_required'];

        $a = $crud->read('company_announcements', ['id'=>$id], ['company_id'], false);
        if (!$a) return ['success'=>false, 'error'=>'not_found'];
        $cid = (int)$a['company_id'];

        // izin kodu seçimi
        $perm = $to==='hidden'   ? 'company.ann.hide'
              : ($to==='archived'? 'company.ann.archive' : 'company.ann.update');

        if (!self::can($actor, $cid, $crud, $perm)) {
            return ['success'=>false, 'error'=>'not_authorized'];
        }

        $upd = ['status'=>$to, 'updated_at'=>self::now()];
        if ($to === 'hidden')   { $upd['hidden_at']=self::now();   $upd['hidden_by']=$actor; }
        if ($to === 'archived') { $upd['archived_at']=self::now(); $upd['archived_by']=$actor; }
        if ($clearHidden)       { $upd['hidden_at']=null;   $upd['hidden_by']=null; }
        if ($clearArchived)     { $upd['archived_at']=null; $upd['archived_by']=null; }

        $ok = $crud->update('company_announcements', $upd, ['id'=>$id]);
        return ['success'=>(bool)$ok];
    }

    /** PIN / UNPIN */
    private static function setPinned(array $p, bool $flag): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['success'=>false, 'error'=>'id_required'];

        $a = $crud->read('company_announcements', ['id'=>$id], ['company_id'], false);
        if (!$a) return ['success'=>false, 'error'=>'not_found'];
        $cid = (int)$a['company_id'];

        if (!self::can($actor, $cid, $crud, 'company.ann.pin')) {
            return ['success'=>false, 'error'=>'not_authorized'];
        }

        $ok = $crud->update('company_announcements', [
            'pinned'     => $flag ? 1 : 0,
            'updated_at' => self::now()
        ], ['id'=>$id]);

        return ['success'=>(bool)$ok];
    }

    /** HARD DELETE (tercihen archive önerilir) */
    private static function hardDelete(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['deleted'=>false, 'error'=>'id_required'];

        $a = $crud->read('company_announcements', ['id'=>$id], ['company_id'], false);
        if (!$a) return ['deleted'=>false, 'error'=>'not_found'];
        $cid = (int)$a['company_id'];

        if (!self::can($actor, $cid, $crud, 'company.ann.delete')) {
            return ['deleted'=>false, 'error'=>'not_authorized'];
        }

        $ok = $crud->query("DELETE FROM company_announcements WHERE id=:id LIMIT 1", [':id'=>$id]) !== false;
        return ['deleted'=>(bool)$ok];
    }
}
