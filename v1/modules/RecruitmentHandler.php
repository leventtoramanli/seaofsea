<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/permissionGate.php';

class RecruitmentHandler
{
    /* ===========================
     * Helpers
     * =========================== */

    private static function nowUtc(): string {
        return gmdate('Y-m-d H:i:s');
    }

    private static function ip(): ?string {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private static function ua(): ?string {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    private static function audit(Crud $crud, int $actorId, string $entityType, ?int $entityId, string $action, array $meta = []): void
    {
        $crud->create('audit_events', [
            'actor_id'    => $actorId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'meta'        => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip'          => self::ip(),
            'user_agent'  => self::ua(),
            'created_at'  => self::nowUtc(),
        ]);
    }

    private static function require_int($value, string $name): ?array {
        $i = (int)($value ?? 0);
        if ($i <= 0) {
            return ['success'=>false, 'message'=>"$name is required", 'code'=>422];
        }
        return ['ok'=>$i];
    }

    private static function require_non_empty(?string $value, string $name): ?array {
        $v = trim((string)($value ?? ''));
        if ($v === '') {
            return ['success'=>false, 'message'=>"$name is required", 'code'=>422];
        }
        return ['ok'=>$v];
    }

    private static function allow_statuses(): array {
        return ['draft','published','closed','archived'];
    }

    private static function allow_app_statuses(): array {
        return ['submitted','under_review','shortlisted','interview','offered','hired','rejected','withdrawn'];
    }

    private static function is_active_app_status(string $s): bool {
        return in_array($s, ['submitted','under_review','shortlisted','interview','offered'], true);
    }

    /* ===========================
     * POST (İLAN) ACTIONS
     * =========================== */

    public static function post_create(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;
        Gate::check('recruitment.post.create', $companyId['ok']);

        $title = self::require_non_empty($p['title'] ?? null, 'title');
        if (isset($title['success'])) return $title;

        $desc  = trim((string)($p['description'] ?? ''));
        $posId = isset($p['position_id']) ? (int)$p['position_id'] : null;

        $data = [
            'company_id'   => $companyId['ok'],
            'title'        => $title['ok'],
            'description'  => $desc,
            'position_id'  => $posId,
            'status'       => 'draft',
            'created_at'   => self::nowUtc(),
            'updated_at'   => self::nowUtc(),
        ];

        $id = $crud->create('job_posts', $data);
        if (!$id) {
            return ['success'=>false, 'message'=>'Failed to create post', 'code'=>500];
        }

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id, 'create', ['payload'=>$data]);

        return ['success'=>true, 'message'=>'Post created', 'data'=>['id'=>(int)$id], 'code'=>200];
    }

    public static function post_update(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        $post = $rows[0];

        Gate::check('recruitment.post.update', (int)$post['company_id']);

        $allowed = ['title','description','position_id','location','employment_type'];
        $update = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $p)) $update[$k] = $p[$k];
        }
        if (!$update) return ['success'=>true, 'message'=>'No changes', 'data'=>['id'=>(int)$id['ok']], 'code'=>200];

        $update['updated_at'] = self::nowUtc();

        $ok = $crud->update('job_posts', $update, ['id'=>$id['ok']]);
        if (!$ok) {
            return ['success'=>false, 'message'=>'Update failed', 'code'=>500];
        }

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id['ok'], 'update', ['changes'=>$update]);

        return ['success'=>true, 'message'=>'Post updated', 'data'=>['id'=>(int)$id['ok']], 'code'=>200];
    }

    public static function post_publish(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        $post = $rows[0];

        Gate::check('recruitment.post.publish', (int)$post['company_id']);

        $ok = $crud->update('job_posts', [
            'status'       => 'published',
            'published_at' => self::nowUtc(),
            'updated_at'   => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Publish failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id['ok'], 'publish', []);

        return ['success'=>true, 'message'=>'Post published', 'data'=>['id'=>(int)$id['ok']], 'code'=>200];
    }

    public static function post_close(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        $post = $rows[0];

        Gate::check('recruitment.post.close', (int)$post['company_id']);

        $ok = $crud->update('job_posts', [
            'status'     => 'closed',
            'updated_at' => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Close failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id['ok'], 'close', []);

        return ['success'=>true, 'message'=>'Post closed', 'data'=>['id'=>(int)$id['ok']], 'code'=>200];
    }

    public static function post_detail(array $p): array
    {
        $auth = Auth::requireAuth(); // public gösterim kurgulanabilir; şimdilik auth zorunlu
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        $post = $rows[0];

        // Yayınlanmamışsa görmeye izin var mı?
        if ($post['status'] !== 'published') {
            Gate::check('recruitment.post.view', (int)$post['company_id']);
        }

        return ['success'=>true, 'message'=>'OK', 'data'=>['post'=>$post], 'code'=>200];
    }
    public static function post_overview(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;
        $recent = max(0, (int)($p['recent'] ?? 5)); // son X başvuru

        // İlanı çek
        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        $post = $rows[0];

        $companyId = (int)$post['company_id'];
        // İzin: ilan görüntüleme
        Gate::check('recruitment.post.view', $companyId);

        // Başvuru istatistikleri (bu ilan için)
        Gate::check('recruitment.app.view_company', $companyId); // şirket içi başvurulara erişim

        $statsRows = $crud->query(
            "SELECT status, COUNT(*) c
            FROM applications
            WHERE company_id=:cid AND job_post_id=:pid
            GROUP BY status",
            [':cid'=>$companyId, ':pid'=>(int)$post['id']]
        );

        $by = [
            'submitted'=>0,'under_review'=>0,'shortlisted'=>0,'interview'=>0,
            'offered'=>0,'hired'=>0,'rejected'=>0,'withdrawn'=>0
        ];
        $total = 0;
        foreach ($statsRows as $r) {
            $st = (string)$r['status'];
            $c  = (int)$r['c'];
            if (isset($by[$st])) $by[$st] = $c;
            $total += $c;
        }
        $activeSet = ['submitted','under_review','shortlisted','interview','offered'];
        $active = 0; foreach ($activeSet as $st) $active += $by[$st];

        // Son başvurular
        $recentItems = [];
        if ($recent > 0) {
            $recentItems = $crud->query(
                "SELECT id, user_id, company_id, job_post_id, reviewer_user_id, status, created_at
                FROM applications
                WHERE company_id=:cid AND job_post_id=:pid
                ORDER BY id DESC
                LIMIT :lim",
                [':cid'=>$companyId, ':pid'=>(int)$post['id'], ':lim'=>$recent]
            );
        }

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>[
                'post'=>$post,
                'app_stats'=>[
                    'by_status'=>$by,
                    'total'=>$total,
                    'active'=>$active,
                ],
                'recent_applications'=>$recentItems,
            ],
            'code'=>200
        ];
    }

    public static function post_list(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = isset($p['company_id']) ? (int)$p['company_id'] : null;
        $status    = isset($p['status']) ? (string)$p['status'] : null;
        $q         = trim((string)($p['q'] ?? ''));
        $page      = max(1, (int)($p['page'] ?? 1));
        $perPage   = min(100, max(1, (int)($p['per_page'] ?? 25)));
        $offset    = ($page - 1) * $perPage;

        $params = [];
        $whereSql = '1=1';

        if ($companyId) {
            $whereSql .= ' AND company_id = :cid';
            $params[':cid'] = $companyId;
        }

        $canSeeAll = false;
        if ($companyId) {
            // şirket içi tüm statülerde listeleme izni var mı?
            $canSeeAll = Gate::allows('recruitment.post.view', $companyId);
        }

        if ($status) {
            $whereSql .= ' AND status = :st';
            $params[':st'] = $status;
        } else {
            // İzinsiz genel liste: sadece published
            if (!$canSeeAll) {
                $whereSql .= " AND status = 'published'";
            }
        }

        if ($q !== '') {
            $whereSql .= ' AND (title LIKE :q OR description LIKE :q)';
            $params[':q'] = '%'.$q.'%';
        }

        $rows = $crud->query("
            SELECT SQL_CALC_FOUND_ROWS *
            FROM job_posts
            WHERE $whereSql
            ORDER BY id DESC
            LIMIT :lim OFFSET :off
        ", array_merge($params, [
            ':lim' => $perPage,
            ':off' => $offset,
        ]));

        $total = $crud->query("SELECT FOUND_ROWS() AS t")[0]['t'] ?? 0;

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>['items'=>$rows, 'page'=>$page, 'per_page'=>$perPage, 'total'=>(int)$total],
            'code'=>200
        ];
    }

    /* ===========================
     * APPLICATION (BAŞVURU) ACTIONS
     * =========================== */

    public static function app_submit(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        Gate::check('recruitment.app.submit', $companyId['ok']);

        $jobPostId = isset($p['job_post_id']) ? (int)$p['job_post_id'] : null;
        $cover     = trim((string)($p['cover_letter'] ?? ''));
        $cv        = isset($p['cv_snapshot']) ? json_encode($p['cv_snapshot'], JSON_UNESCAPED_UNICODE) : null;
        $attach    = isset($p['attachments']) ? json_encode($p['attachments'], JSON_UNESCAPED_UNICODE) : null;

        // aynı user + job için aktif başvuru var mı?
        if ($jobPostId) {
            $active = $crud->query("
                SELECT id,status FROM applications
                WHERE user_id=:u AND job_post_id=:j
                  AND status IN ('submitted','under_review','shortlisted','interview','offered')
                LIMIT 1
            ", [':u'=>$auth['user_id'], ':j'=>$jobPostId]);
            if ($active) {
                return ['success'=>false, 'message'=>'Active application already exists', 'code'=>409];
            }
        }

        $ins = [
            'user_id'      => (int)$auth['user_id'],
            'company_id'   => $companyId['ok'],
            'job_post_id'  => $jobPostId,
            'cover_letter' => $cover,
            'cv_snapshot'  => $cv,
            'attachments'  => $attach,
            'status'       => 'submitted',
            'created_at'   => self::nowUtc(),
            'updated_at'   => self::nowUtc(),
        ];

        $id = $crud->create('applications', $ins);
        if (!$id) return ['success'=>false, 'message'=>'Submit failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$id, 'submit', ['payload'=>$ins]);

        return ['success'=>true, 'message'=>'Application submitted', 'data'=>['id'=>(int)$id], 'code'=>200];
    }

    public static function app_list_for_company(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        Gate::check('recruitment.app.view_company', $companyId['ok']);

        $status    = isset($p['status']) ? (string)$p['status'] : null;
        $jobPostId = isset($p['job_post_id']) ? (int)$p['job_post_id'] : null; // ← eklendi
        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = min(100, max(1, (int)($p['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $params = [':cid'=>$companyId['ok']];
        $where  = 'company_id=:cid';

        if ($status) {
            $where .= ' AND status=:st';
            $params[':st'] = $status;
        }
        if ($jobPostId) { // ← eklendi
            $where .= ' AND job_post_id=:j';
            $params[':j'] = $jobPostId;
        }

        $rows = $crud->query("
            SELECT SQL_CALC_FOUND_ROWS id, user_id, company_id, job_post_id, reviewer_user_id, status, created_at, updated_at
            FROM applications
            WHERE $where
            ORDER BY id DESC
            LIMIT :lim OFFSET :off
        ", array_merge($params, [':lim'=>$perPage, ':off'=>$offset]));
        $total = $crud->query("SELECT FOUND_ROWS() AS t")[0]['t'] ?? 0;

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>['items'=>$rows, 'page'=>$page, 'per_page'=>$perPage, 'total'=>(int)$total],
            'code'=>200
        ];
    }

    public static function app_list_for_user(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $userId = isset($p['user_id']) ? (int)$p['user_id'] : (int)$auth['user_id'];
        $isSelf = ($userId === (int)$auth['user_id']);

        if (!$isSelf) {
            // başka birinin başvurularını görmek için şirket ve permission gerekir
            $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
            if (isset($companyId['success'])) return $companyId;
            Gate::check('recruitment.app.view_company', $companyId['ok']);
        }

        $rows = $crud->read('applications', ['user_id'=>$userId], ['id','company_id','job_post_id','reviewer_user_id','status','created_at','updated_at']);
        return ['success'=>true, 'message'=>'OK', 'data'=>['items'=>$rows], 'code'=>200];
    }

    public static function app_update_status(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $new   = self::require_non_empty($p['new_status'] ?? null, 'new_status');
        if (isset($new['success'])) return $new;
        $newStatus = $new['ok'];

        if (!in_array($newStatus, self::allow_app_statuses(), true)) {
            return ['success'=>false, 'message'=>'Invalid status', 'code'=>422];
        }

        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        $app = $rows[0];

        Gate::check('recruitment.app.status.update', (int)$app['company_id']);

        $from = (string)$app['status'];
        if ($from === $newStatus) {
            return [
                'success'=>true,
                'message'=>'No change',
                'data'=>['id'=>(int)$appId['ok'], 'new_status'=>$newStatus],
                'code'=>200
            ];
        }

        // --- Geçiş kuralları ---
        $allowed = [
            'submitted'    => ['under_review','withdrawn','rejected'],
            'under_review' => ['shortlisted','interview','rejected','withdrawn'],
            'shortlisted'  => ['interview','offered','rejected','withdrawn'],
            'interview'    => ['offered','rejected','withdrawn'],
            'offered'      => ['hired','rejected','withdrawn'],
            'hired'        => [],
            'rejected'     => [],
            'withdrawn'    => [],
        ];

        if (!isset($allowed[$from]) || !in_array($newStatus, $allowed[$from], true)) {
            return ['success'=>false, 'message'=>"Invalid transition: $from → $newStatus", 'code'=>422];
        }

        // Güncelle
        $ok = $crud->update('applications', [
            'status'     => $newStatus,
            'updated_at' => self::nowUtc(),
        ], ['id'=>$appId['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Status update failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$appId['ok'], 'status_update', [
            'old'=>$from,
            'new'=>$newStatus,
            'note'=> $p['note'] ?? null,
        ]);

        return [
            'success'=>true,
            'message'=>'Status updated',
            'data'=>['id'=>(int)$appId['ok'], 'new_status'=>$newStatus],
            'code'=>200
        ];
    }

    public static function app_add_note(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id','user_id'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        $app = $rows[0];

        // şirket tarafı review izni
        Gate::check('recruitment.app.review', (int)$app['company_id']);

        $note = self::require_non_empty($p['note'] ?? null, 'note');
        if (isset($note['success'])) return $note;

        self::audit($crud, (int)$auth['user_id'], 'application_note', (int)$appId['ok'], 'add_note', ['text'=>$note['ok']]);

        return ['success'=>true, 'message'=>'Note added', 'data'=>['application_id'=>(int)$appId['ok']], 'code'=>200];
    }

    public static function app_notes(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        // izin kontrolü (şirket review) ya da başvuru sahibi kendisi
        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id','user_id'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        $app = $rows[0];

        $isOwner = ((int)$app['user_id'] === (int)$auth['user_id']);
        if (!$isOwner) {
            Gate::check('recruitment.app.review', (int)$app['company_id']);
        }

        $notes = $crud->query("
            SELECT id, actor_id, action, meta, created_at
            FROM audit_events
            WHERE entity_type='application_note' AND entity_id=:id
            ORDER BY id DESC
        ", [':id'=>$appId['ok']]);

        // meta içinden text çek
        $items = array_map(function($r){
            $meta = json_decode($r['meta'] ?? '[]', true) ?? [];
            return [
                'id'         => (int)$r['id'],
                'actor_id'   => (int)$r['actor_id'],
                'text'       => $meta['text'] ?? null,
                'created_at' => $r['created_at'],
            ];
        }, $notes);

        return ['success'=>true, 'message'=>'OK', 'data'=>['items'=>$items], 'code'=>200];
    }

    public static function app_withdraw(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        $app = $rows[0];

        // yalnız başvuru sahibi çekebilir
        if ((int)$app['user_id'] !== (int)$auth['user_id']) {
            return ['success'=>false, 'message'=>'Only owner can withdraw', 'code'=>403];
        }

        if (!self::is_active_app_status((string)$app['status'])) {
            return ['success'=>false, 'message'=>'Only active applications can be withdrawn', 'code'=>409];
        }

        $ok = $crud->update('applications', [
            'status'     => 'withdrawn',
            'updated_at' => self::nowUtc(),
        ], ['id'=>$appId['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Withdraw failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$appId['ok'], 'withdraw', []);

        return ['success'=>true, 'message'=>'Application withdrawn', 'data'=>['id'=>(int)$appId['ok']], 'code'=>200];
    }

    public static function app_assign_reviewer(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId  = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['*'], true);
        if (!$rows) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        $app = $rows[0];

        Gate::check('recruitment.app.assign', (int)$app['company_id']);

        $revId = self::require_int($p['reviewer_user_id'] ?? null, 'reviewer_user_id');
        if (isset($revId['success'])) return $revId;
        if ($revId['ok'] <= 0) {
            return ['success'=>false, 'message'=>'Invalid reviewer_user_id', 'code'=>422];
        }

        // (Opsiyonel) users tablosu adı farklı olabilir; mevcutsa kontrol et, yoksa atla
        try {
            $rev = $crud->read('users', ['id'=>$revId['ok']], ['id'], true);
            if (is_array($rev) && !$rev) {
                return ['success'=>false, 'message'=>'Reviewer not found', 'code'=>422];
            }
        } catch (\Throwable $e) {
            // users tablosu farklıysa kontrolü atla
        }

        $ok = $crud->update('applications', [
            'reviewer_user_id' => $revId['ok'],
            'updated_at'       => self::nowUtc(),
        ], ['id'=>$appId['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Assignment failed', 'code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$appId['ok'], 'assign_reviewer', [
            'reviewer_user_id'=>$revId['ok'],
            'db_updated' => true,
        ]);

        return ['success'=>true, 'message'=>'Reviewer assigned', 'data'=>['id'=>(int)$appId['ok'], 'reviewer_user_id'=>$revId['ok']], 'code'=>200];
    }

    /* ===========================
     * İskelet/TODO kalanlar
     * =========================== */

    public static function post_archive(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        $rows = $crud->read('job_posts', ['id'=>$id['ok']], ['*'], true);
        if (!$rows) {
            return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        }
        $post = $rows[0];

        // İzin kontrolü:
        // Eğer sistemde 'recruitment.post.archive' izni tanımlı ve kullanıcının erişimi varsa onu kullan.
        // Geçiş sürecinde bu izin yoksa (veya tanımlı değilse) 'recruitment.post.close' ile geriye dönük uyumluluk sağla.
        try {
            Gate::check('recruitment.post.archive', (int)$post['company_id']);
        } catch (\Throwable $e) {
            // Archive izni yoksa/henüz tanımlı değilse close izni ile koru (geçici geri uyumluluk)
            Gate::check('recruitment.post.close', (int)$post['company_id']);
        }

        // Sadece kapalı ilanlar arşivlenebilir
        if ((string)$post['status'] !== 'closed') {
            return ['success'=>false, 'message'=>'Only closed posts can be archived', 'code'=>409];
        }

        $ok = $crud->update('job_posts', [
            'status'     => 'archived',
            'updated_at' => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) {
            return ['success'=>false, 'message'=>'Archive failed', 'code'=>500];
        }

        self::audit(
            $crud,
            (int)$auth['user_id'],
            'job_post',
            (int)$id['ok'],
            'archive',
            []
        );

        return [
            'success' => true,
            'message' => 'Post archived',
            'data'    => ['id' => (int)$id['ok']],
            'code'    => 200
        ];
    }
    public static function post_stats(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        // Şirket içi ilanları görebilen herkes istatistik de görebilsin
        Gate::check('recruitment.post.view', $companyId['ok']);

        $rows = $crud->query("
            SELECT status, COUNT(*) as c
            FROM job_posts
            WHERE company_id = :cid
            GROUP BY status
        ", [':cid' => $companyId['ok']]);

        $by = ['draft'=>0,'published'=>0,'closed'=>0,'archived'=>0];
        $total = 0;
        foreach ($rows as $r) {
            $st = (string)$r['status'];
            $c  = (int)$r['c'];
            if (isset($by[$st])) $by[$st] = $c;
            $total += $c;
        }

        return ['success'=>true,'message'=>'OK','data'=>[
            'by_status'=>$by,
            'total'=>$total
        ],'code'=>200];
    }

    public static function app_stats(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        Gate::check('recruitment.app.view_company', $companyId['ok']);

        $jobPostId = isset($p['job_post_id']) ? (int)$p['job_post_id'] : null;

        $params = [':cid'=>$companyId['ok']];
        $where  = 'company_id=:cid';
        if ($jobPostId) {
            $where .= ' AND job_post_id=:j';
            $params[':j'] = $jobPostId;
        }

        $rows = $crud->query("
            SELECT status, COUNT(*) as c
            FROM applications
            WHERE $where
            GROUP BY status
        ", $params);

        $by = [
            'submitted'=>0,'under_review'=>0,'shortlisted'=>0,'interview'=>0,
            'offered'=>0,'hired'=>0,'rejected'=>0,'withdrawn'=>0
        ];
        $total = 0;
        foreach ($rows as $r) {
            $st = (string)$r['status'];
            $c  = (int)$r['c'];
            if (isset($by[$st])) $by[$st] = $c;
            $total += $c;
        }

        // Ek faydalı alanlar
        $activeSet = ['submitted','under_review','shortlisted','interview','offered'];
        $active = 0; foreach ($activeSet as $st) $active += $by[$st];

        // (opsiyonel) atanmamış başvuru sayısı
        $unassigned = ($jobPostId)
            ? ($crud->query("SELECT COUNT(*) c FROM applications WHERE $where AND reviewer_user_id IS NULL", $params)[0]['c'] ?? 0)
            : ($crud->query("SELECT COUNT(*) c FROM applications WHERE company_id=:cid AND reviewer_user_id IS NULL", [':cid'=>$companyId['ok']])[0]['c'] ?? 0);

        return ['success'=>true,'message'=>'OK','data'=>[
            'by_status'=>$by,
            'total'=>$total,
            'active'=>$active,
            'unassigned'=>(int)$unassigned,
        ],'code'=>200];
    }   
}
