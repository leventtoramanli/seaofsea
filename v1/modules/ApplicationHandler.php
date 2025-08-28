<?php
// api/v1/modules/ApplicationHandler.php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';

class ApplicationHandler
{
    /* ===== Router köprüleri ===== */
    public static function create(array $p = []): array          { return self::createApp($p); }
    public static function list_by_user(array $p = []): array    { return self::listByUser($p); }
    public static function list_by_company(array $p = []): array { return self::listByCompany($p); }
    public static function detail(array $p = []): array          { return self::detailApp($p); }
    public static function update(array $p = []): array          { return self::updateApp($p); }
    public static function move_status(array $p = []): array     { return self::moveStatus($p); }
    public static function withdraw(array $p = []): array        { return self::withdrawApp($p); }
    public static function assign_reviewer(array $p = []): array { return self::assignReviewer($p); }
    public static function add_note(array $p = []): array        { return self::addNote($p); }
    public static function list_notes(array $p = []): array      { return self::listNotes($p); }
    public static function timeline(array $p = []): array        { return self::timelineCore($p); }
    public static function time_line(array $p = []): array        { return self::timelineCore($p); }

    /* ====== Config / helpers ====== */

    private static array $statusAllowed = [
        'draft','submitted','under_review','shortlisted','interview','offer','hired','rejected','withdrawn'
    ];

    // Uygulamada opsiyonel planlanan kolonlar olabilir (assigned_to, tags, attachments, source)
    private static array $appOptionalCols = [];

    private static function colExists(string $table, string $col): bool
    {
        $key = $table . '.' . $col;
        if (array_key_exists($key, self::$appOptionalCols)) {
            return self::$appOptionalCols[$key];
        }
        try {
            $pdo = DB::getInstance();
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
            $stmt->execute([':c' => $col]);
            $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            self::$appOptionalCols[$key] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            Logger::error("colExists error", ['table'=>$table, 'col'=>$col, 'err'=>$e->getMessage()]);
            self::$appOptionalCols[$key] = false;
            return false;
        }
    }

    private static function canCompany(int $actorUserId, int $companyId, string $permCode): bool
    {
        $crud = new Crud($actorUserId);

        $c = $crud->read('companies', ['id'=>$companyId], ['created_by'], false);
        $isCreator = $c && (int)($c['created_by'] ?? 0) === $actorUserId;

        $isAdmin = (bool)$crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
              AND r.scope='company' AND r.name='admin'
            LIMIT 1
        ", [':u'=>$actorUserId, ':c'=>$companyId]);

        if ($isCreator || $isAdmin) return true;

        // PermissionService (RBAC+grant) kontrolü
        if (PermissionService::hasPermission($actorUserId, $permCode, $companyId)) {
            return true;
        }

        // Gate varsa destekleyelim (opsiyonel)
        if (class_exists('Gate') && method_exists('Gate','allows')) {
            return Gate::allows($permCode, $companyId);
        }

        return false;
    }

    private static function loadApp(Crud $crud, int $id): ?array
    {
        $row = $crud->read('applications', ['id'=>$id], false);
        return $row ?: null;
    }

    private static function ensureJobInCompany(Crud $crud, int $jobId, int $companyId): bool
    {
        $j = $crud->read('job_posts', ['id'=>$jobId], ['company_id'], false);
        return $j && (int)$j['company_id'] === $companyId;
    }

    private static function ipUA(): array
    {
        $ip  = $_SERVER['REMOTE_ADDR']    ?? null;
        $uax = $_SERVER['HTTP_USER_AGENT']?? null;
        return [$ip, $uax];
    }

    /* ============ CREATE ============ */
    private static function createApp(array $p): array
    {
        $auth   = Auth::requireAuth();
        $actor  = (int)$auth['user_id'];
        $crud   = new Crud($actor);

        $companyId = (int)($p['company_id'] ?? 0);
        $jobPostId = isset($p['job_post_id']) ? (int)$p['job_post_id'] : 0;
        $targetUserId = (int)($p['user_id'] ?? $actor);
        $status  = trim((string)($p['status'] ?? 'submitted'));
        $status  = in_array($status, self::$statusAllowed, true) ? $status : 'submitted';

        if ($companyId <= 0 || $jobPostId <= 0) {
            return ['created'=>false, 'error'=>'company_id_and_job_post_id_required'];
        }

        // job → company doğrula
        if (!self::ensureJobInCompany($crud, $jobPostId, $companyId)) {
            return ['created'=>false, 'error'=>'job_not_in_company'];
        }

        // Başkasının adına oluşturma -> manage izni gerekli
        if ($targetUserId !== $actor) {
            if (!self::canCompany($actor, $companyId, 'application.manage')) {
                return ['created'=>false, 'error'=>'not_authorized'];
            }
        }

        $data = [
            'user_id'     => $targetUserId,
            'company_id'  => $companyId,
            'job_post_id' => $jobPostId,
            'cover_letter'=> isset($p['cover_letter']) ? (string)$p['cover_letter'] : null,
            'status'      => $status,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => null,
        ];

        // opsiyonel alanlar
        if (isset($p['cv_snapshot'])) {
            $snap = is_array($p['cv_snapshot']) ? json_encode($p['cv_snapshot'], JSON_UNESCAPED_UNICODE) : (string)$p['cv_snapshot'];
            $data['cv_snapshot'] = $snap;
        }
        if (isset($p['attachments']) && self::colExists('applications', 'attachments')) {
            $data['attachments'] = (string)$p['attachments'];
        }
        if (isset($p['tags']) && self::colExists('applications', 'tags')) {
            $data['tags'] = (string)$p['tags'];
        }
        if (isset($p['source']) && self::colExists('applications', 'source')) {
            $data['source'] = (string)$p['source'];
        }

        // null'ları temizle (Crud insert için)
        $data = array_filter($data, fn($v)=>$v !== null);

        $id = $crud->create('applications', $data);
        if (!$id) {
            return ['created'=>false, 'error'=>'db_insert_failed'];
        }

        // audit
        [$ip,$ua] = self::ipUA();
        $crud->create('audit_events', [
            'actor_id'   => $actor,
            'entity_type'=> 'application',
            'entity_id'  => (int)$id,
            'action'     => 'created',
            'meta'       => json_encode(['status'=>$status], JSON_UNESCAPED_UNICODE),
            'ip'         => $ip,
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id'=>(int)$id, 'status'=>$status];
    }

    /* ============ LIST BY USER ============ */
    private static function listByUser(array $p): array
    {
        $auth   = Auth::requireAuth();
        $actor  = (int)$auth['user_id'];
        $crud   = new Crud($actor);

        $targetUserId = (int)($p['user_id'] ?? $actor);
        $companyId    = isset($p['company_id']) ? (int)$p['company_id'] : null;

        if ($targetUserId !== $actor) {
            if ($companyId === null) {
                return ['items'=>[], 'total'=>0, 'limit'=>25, 'offset'=>0, 'error'=>'company_id_required_for_foreign_user'];
            }
            if (!self::canCompany($actor, $companyId, 'application.view')) {
                return ['items'=>[], 'total'=>0, 'limit'=>25, 'offset'=>0, 'error'=>'not_authorized'];
            }
        }

        $status  = isset($p['status']) ? (string)$p['status'] : null;
        $limit   = max(1, min(100, (int)($p['limit'] ?? 25)));
        $offset  = max(0, (int)($p['offset'] ?? 0));
        $q       = isset($p['q']) ? trim((string)$p['q']) : null;
        $from    = isset($p['date_from']) ? (string)$p['date_from'] : null;
        $to      = isset($p['date_to'])   ? (string)$p['date_to']   : null;

        $where = ["a.user_id = :uid"];
        $bind  = [':uid'=>$targetUserId];

        if ($companyId !== null) { $where[] = "a.company_id = :cid"; $bind[':cid'] = $companyId; }
        if ($status)             { $where[] = "a.status = :st";      $bind[':st']  = $status; }
        if ($q)                  { $where[] = "a.cover_letter LIKE CONCAT('%', :q, '%')"; $bind[':q']=$q; }
        if ($from)               { $where[] = "DATE(a.created_at) >= :df"; $bind[':df']=$from; }
        if ($to)                 { $where[] = "DATE(a.created_at) <= :dt"; $bind[':dt']=$to; }

        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        $cnt = $crud->query("SELECT COUNT(*) total FROM applications a $whereSql", $bind);
        $total = (int)($cnt[0]['total'] ?? 0);

        $rows = $crud->query("
            SELECT a.*
            FROM applications a
            $whereSql
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT $limit OFFSET $offset
        ", $bind) ?: [];

        return ['items'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    }

    /* ============ LIST BY COMPANY ============ */
    private static function listByCompany(array $p): array
    {
        $auth   = Auth::requireAuth();
        $actor  = (int)$auth['user_id'];
        $crud   = new Crud($actor);

        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId <= 0) return ['items'=>[], 'total'=>0, 'limit'=>25, 'offset'=>0, 'error'=>'company_id_required'];

        // job.applications.view veya application.view, ya da kurucu/admin
        $canView = self::canCompany($actor, $companyId, 'job.applications.view')
                || self::canCompany($actor, $companyId, 'application.view');
        if (!$canView) {
            return ['items'=>[], 'total'=>0, 'limit'=>25, 'offset'=>0, 'error'=>'not_authorized'];
        }

        $jobId  = isset($p['job_post_id']) ? (int)$p['job_post_id'] : null;
        $status = isset($p['status']) ? (string)$p['status'] : null;
        $q      = isset($p['q']) ? trim((string)$p['q']) : null;
        $from   = isset($p['date_from']) ? (string)$p['date_from'] : null;
        $to     = isset($p['date_to'])   ? (string)$p['date_to']   : null;

        $limit  = max(1, min(100, (int)($p['limit'] ?? 25)));
        $offset = max(0, (int)($p['offset'] ?? 0));

        $where = ["a.company_id = :cid"];
        $bind  = [':cid'=>$companyId];

        if ($jobId)    { $where[] = "a.job_post_id = :jid"; $bind[':jid'] = $jobId; }
        if ($status)   { $where[] = "a.status = :st";       $bind[':st']  = $status; }
        if ($q) {
            $where[] = "(a.cover_letter LIKE CONCAT('%', :q, '%')
                      OR u.name LIKE CONCAT('%', :q, '%')
                      OR u.surname LIKE CONCAT('%', :q, '%'))";
            $bind[':q'] = $q;
        }
        if ($from) { $where[] = "DATE(a.created_at) >= :df"; $bind[':df']=$from; }
        if ($to)   { $where[] = "DATE(a.created_at) <= :dt"; $bind[':dt']=$to; }

        $whereSql = 'WHERE '.implode(' AND ', $where);

        $cnt = $crud->query("
            SELECT COUNT(*) total
            FROM applications a
            JOIN users u ON u.id = a.user_id
            $whereSql
        ", $bind);
        $total = (int)($cnt[0]['total'] ?? 0);

        $rows = $crud->query("
            SELECT a.*, u.name, u.surname, u.email,
                   jp.title AS job_title
            FROM applications a
            JOIN users u ON u.id = a.user_id
            LEFT JOIN job_posts jp ON jp.id = a.job_post_id
            $whereSql
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT $limit OFFSET $offset
        ", $bind) ?: [];

        return ['items'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    }

    /* ============ DETAIL ============ */
    private static function detailApp(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['found'=>false, 'error'=>'id_required'];

        $a = self::loadApp($crud, $id);
        if (!$a) return ['found'=>false];

        $owner = (int)$a['user_id'] === $actor;
        if (!$owner) {
            $cid = (int)$a['company_id'];
            if (!self::canCompany($actor, $cid, 'application.view')) {
                return ['found'=>false, 'error'=>'not_authorized'];
            }
        }

        // enrichment (opsiyonel)
        $u = $crud->read('users', ['id'=>(int)$a['user_id']], ['name','surname','email'], false);
        if ($u) {
            $a['applicant_name'] = trim(($u['name'] ?? '').' '.($u['surname'] ?? ''));
            $a['applicant_email']= $u['email'] ?? null;
        }
        if (!empty($a['job_post_id'])) {
            $jp = $crud->read('job_posts', ['id'=>(int)$a['job_post_id']], ['title'], false);
            if ($jp) $a['job_title'] = $jp['title'];
        }

        return ['application'=>$a];
    }

    /* ============ UPDATE ============ */
    private static function updateApp(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['updated'=>false, 'error'=>'id_required'];

        $a = self::loadApp($crud, $id);
        if (!$a) return ['updated'=>false, 'error'=>'not_found'];

        $cid = (int)$a['company_id'];
        $owner = (int)$a['user_id'] === $actor;

        $allowAsOwner = $owner && in_array((string)$a['status'], ['draft','submitted'], true);
        if (!$allowAsOwner) {
            if (!self::canCompany($actor, $cid, 'application.review')) {
                return ['updated'=>false, 'error'=>'not_authorized'];
            }
        }

        $upd = ['updated_at'=>date('Y-m-d H:i:s')];
        if (array_key_exists('cover_letter', $p)) {
            $upd['cover_letter'] = (string)$p['cover_letter'];
        }
        if (array_key_exists('cv_snapshot', $p)) {
            $upd['cv_snapshot'] = is_array($p['cv_snapshot'])
                ? json_encode($p['cv_snapshot'], JSON_UNESCAPED_UNICODE)
                : (string)$p['cv_snapshot'];
        }
        if (array_key_exists('attachments', $p) && self::colExists('applications','attachments')) {
            $upd['attachments'] = (string)$p['attachments'];
        }
        if (array_key_exists('tags', $p) && self::colExists('applications','tags')) {
            $upd['tags'] = (string)$p['tags'];
        }

        if (count($upd) === 1) {
            return ['updated'=>true]; // no-op
        }

        $ok = $crud->update('applications', $upd, ['id'=>$id]);
        return ['updated'=>(bool)$ok];
    }

    /* ============ MOVE STATUS ============ */
    private static function moveStatus(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        $to = (string)($p['to'] ?? '');
        $note = isset($p['note']) ? (string)$p['note'] : null;

        if ($id <= 0 || $to === '') return ['moved'=>false, 'error'=>'id_and_to_required'];
        if (!in_array($to, self::$statusAllowed, true)) {
            return ['moved'=>false, 'error'=>'invalid_status'];
        }

        $a = self::loadApp($crud, $id);
        if (!$a) return ['moved'=>false, 'error'=>'not_found'];

        $cid = (int)$a['company_id'];
        if (!self::canCompany($actor, $cid, 'application.manage')) {
            return ['moved'=>false, 'error'=>'not_authorized'];
        }

        $from = (string)$a['status'];
        if ($from === $to) {
            return ['moved'=>true, 'from'=>$from, 'to'=>$to]; // idempotent
        }

        $ok = $crud->update('applications', [
            'status'     => $to,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);
        if (!$ok) return ['moved'=>false, 'error'=>'db_update_failed'];

        // audit → status_change
        [$ip,$ua] = self::ipUA();
        $meta = ['from'=>$from, 'to'=>$to];
        if ($note) $meta['note'] = $note;
        $crud->create('audit_events', [
            'actor_id'   => $actor,
            'entity_type'=> 'application',
            'entity_id'  => $id,
            'action'     => 'status_change',
            'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip'         => $ip,
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['moved'=>true, 'from'=>$from, 'to'=>$to];
    }

    /* ============ WITHDRAW ============ */
    private static function withdrawApp(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['withdrawn'=>false, 'error'=>'id_required'];

        $a = self::loadApp($crud, $id);
        if (!$a) return ['withdrawn'=>false, 'error'=>'not_found'];

        $owner = (int)$a['user_id'] === $actor;
        $cid   = (int)$a['company_id'];

        if (!$owner && !self::canCompany($actor, $cid, 'application.manage')) {
            return ['withdrawn'=>false, 'error'=>'not_authorized'];
        }

        if ((string)$a['status'] === 'withdrawn') {
            return ['withdrawn'=>true]; // idempotent
        }

        $ok = $crud->update('applications', [
            'status'     => 'withdrawn',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        if (!$ok) return ['withdrawn'=>false, 'error'=>'db_update_failed'];

        [$ip,$ua] = self::ipUA();
        $crud->create('audit_events', [
            'actor_id'   => $actor,
            'entity_type'=> 'application',
            'entity_id'  => $id,
            'action'     => 'status_change',
            'meta'       => json_encode(['from'=>$a['status'],'to'=>'withdrawn'], JSON_UNESCAPED_UNICODE),
            'ip'         => $ip,
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['withdrawn'=>true];
    }

    /* ============ ASSIGN REVIEWER ============ */
    private static function assignReviewer(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $id  = (int)($p['id'] ?? 0);
        $uid = (int)($p['assigned_to'] ?? 0);

        if ($id <= 0 || $uid <= 0) return ['assigned'=>false, 'error'=>'id_and_assigned_to_required'];
        if (!self::colExists('applications','assigned_to')) {
            return ['assigned'=>false, 'error'=>'assigned_to_not_supported'];
        }

        $a = self::loadApp($crud, $id);
        if (!$a) return ['assigned'=>false, 'error'=>'not_found'];

        $cid = (int)$a['company_id'];
        if (!self::canCompany($actor, $cid, 'application.manage')) {
            return ['assigned'=>false, 'error'=>'not_authorized'];
        }

        // atanacak kişi şirket üyesi mi?
        $member = $crud->read('company_users', [
            'company_id' => $cid,
            'user_id'    => $uid,
            'is_active'  => 1,
            'status'     => 'approved'
        ], ['id'], false);
        if (!$member) return ['assigned'=>false, 'error'=>'assignee_not_member'];

        $ok = $crud->update('applications', [
            'assigned_to' => $uid,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);
        if (!$ok) return ['assigned'=>false, 'error'=>'db_update_failed'];

        [$ip,$ua] = self::ipUA();
        $crud->create('audit_events', [
            'actor_id'   => $actor,
            'entity_type'=> 'application',
            'entity_id'  => $id,
            'action'     => 'assign_reviewer',
            'meta'       => json_encode(['assigned_to'=>$uid], JSON_UNESCAPED_UNICODE),
            'ip'         => $ip,
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['assigned'=>true];
    }

    /* ============ NOTES ============ */
    private static function addNote(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $appId = (int)($p['application_id'] ?? 0);
        $note  = trim((string)($p['note'] ?? ''));
        if ($appId <= 0 || $note === '') return ['id'=>0, 'error'=>'application_id_and_note_required'];

        $a = self::loadApp($crud, $appId);
        if (!$a) return ['id'=>0, 'error'=>'not_found'];

        $cid = (int)$a['company_id'];
        // not ekleme: review izni yeterli
        if (!self::canCompany($actor, $cid, 'application.review')) {
            return ['id'=>0, 'error'=>'not_authorized'];
        }

        [$ip,$ua] = self::ipUA();
        $id = $crud->create('audit_events', [
            'actor_id'   => $actor,
            'entity_type'=> 'application',
            'entity_id'  => $appId,
            'action'     => 'note',
            'meta'       => json_encode(['note'=>$note], JSON_UNESCAPED_UNICODE),
            'ip'         => $ip,
            'user_agent' => $ua,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id'=>(int)$id];
    }

    private static function listNotes(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $appId = (int)($p['application_id'] ?? 0);
        if ($appId <= 0) return ['items'=>[]];

        $a = self::loadApp($crud, $appId);
        if (!$a) return ['items'=>[]];

        $cid = (int)$a['company_id'];
        $owner = (int)$a['user_id'] === $actor;
        if (!$owner && !self::canCompany($actor, $cid, 'application.view')) {
            return ['items'=>[], 'error'=>'not_authorized'];
        }

        $rows = $crud->query("
            SELECT ae.id, ae.actor_id AS author_user_id, ae.created_at, ae.meta,
                   u.name AS author_name, u.surname AS author_surname
            FROM audit_events ae
            LEFT JOIN users u ON u.id = ae.actor_id
            WHERE ae.entity_type='application' AND ae.entity_id = :id AND ae.action='note'
            ORDER BY ae.created_at DESC, ae.id DESC
        ", [':id'=>$appId]) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $meta = json_decode($r['meta'] ?? '{}', true) ?: [];
            $items[] = [
                'id'             => (int)$r['id'],
                'application_id' => $appId,
                'author_user_id' => isset($r['author_user_id']) ? (int)$r['author_user_id'] : null,
                'note'           => (string)($meta['note'] ?? ''),
                'created_at'     => (string)$r['created_at'],
                'author_name'    => (string)($r['author_name'] ?? ''),
                'author_surname' => (string)($r['author_surname'] ?? ''),
            ];
        }

        return ['items'=>$items];
    }

    /* ============ TIMELINE ============ */
    private static function timelineCore(array $p): array
    {
        $auth  = Auth::requireAuth();
        $actor = (int)$auth['user_id'];
        $crud  = new Crud($actor);

        $appId = (int)($p['application_id'] ?? 0);
        if ($appId <= 0) return ['items'=>[]];

        $a = self::loadApp($crud, $appId);
        if (!$a) return ['items'=>[]];

        $cid = (int)$a['company_id'];
        $owner = (int)$a['user_id'] === $actor;
        if (!$owner && !self::canCompany($actor, $cid, 'application.view')) {
            return ['items'=>[], 'error'=>'not_authorized'];
        }

        $rows = $crud->query("
            SELECT ae.id, ae.action, ae.meta, ae.actor_id, ae.created_at,
                   u.name, u.surname
            FROM audit_events ae
            LEFT JOIN users u ON u.id = ae.actor_id
            WHERE ae.entity_type='application' AND ae.entity_id = :id
              AND ae.action IN ('note','status_change','assign_reviewer','created')
            ORDER BY ae.created_at DESC, ae.id DESC
        ", [':id'=>$appId]) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $action = (string)$r['action'];
            $meta   = json_decode($r['meta'] ?? '{}', true) ?: [];
            if ($action === 'note') {
                $items[] = [
                    'type'       => 'note',
                    'created_at' => (string)$r['created_at'],
                    'note'       => (string)($meta['note'] ?? ''),
                    'author'     => trim(($r['name'] ?? '').' '.($r['surname'] ?? '')) ?: null,
                ];
            } elseif ($action === 'status_change') {
                $items[] = [
                    'type'       => 'history',
                    'created_at' => (string)$r['created_at'],
                    'old_status' => (string)($meta['from'] ?? ''),
                    'new_status' => (string)($meta['to'] ?? ''),
                    'changed_by' => isset($r['actor_id']) ? (int)$r['actor_id'] : null,
                    'author'     => trim(($r['name'] ?? '').' '.($r['surname'] ?? '')) ?: null,
                ];
            } elseif ($action === 'assign_reviewer') {
                $items[] = [
                    'type'       => 'history',
                    'created_at' => (string)$r['created_at'],
                    'old_status' => null,
                    'new_status' => null,
                    'note'       => 'assigned_to: '.($meta['assigned_to'] ?? ''),
                    'author'     => trim(($r['name'] ?? '').' '.($r['surname'] ?? '')) ?: null,
                ];
            } elseif ($action === 'created') {
                $items[] = [
                    'type'       => 'history',
                    'created_at' => (string)$r['created_at'],
                    'old_status' => null,
                    'new_status' => (string)($meta['status'] ?? 'submitted'),
                    'author'     => trim(($r['name'] ?? '').' '.($r['surname'] ?? '')) ?: null,
                ];
            }
        }

        return ['items'=>$items];
    }
}
