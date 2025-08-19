<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class CompanyNotificationHandler
{
    /* ==== Router köprüleri ==== */
    public static function list(array $p = []): array         { return self::listMy($p); }
    public static function mark_read(array $p = []): array    { return self::markRead($p); }
    public static function mark_all_read(array $p = []): array{ return self::markAllRead($p); }
    public static function push_job_post(array $p = []): array{ return self::pushJobPost($p); } // manuel tetik

    /* ==== LIST ==== */
    private static function listMy(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $onlyUnread = (int)($p['only_unread'] ?? 0) === 1;
        $page       = max(1, (int)($p['page'] ?? 1));
        $perPage    = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset     = ($page - 1) * $perPage;

        $conds  = ['user_id' => $userId];
        if ($onlyUnread) $conds['is_read'] = 0;

        $totalRows = $crud->read('company_notifications', $conds, ['COUNT(*) AS c'], false);
        $total     = (int)($totalRows['c'] ?? 0);

        $items = $crud->read(
            'company_notifications',
            $conds,
            ['id','type','title','body','meta','is_read','read_at','created_at'],
            true,
            ['created_at' => 'DESC'],
            [],
            ['limit' => $perPage, 'offset' => $offset]
        ) ?: [];

        // meta JSON decode (uygulama tarafında da yapılabilir)
        foreach ($items as &$it) {
            if (!empty($it['meta'])) {
                $dec = json_decode((string)$it['meta'], true);
                if (is_array($dec)) $it['meta'] = $dec;
            }
        }

        return ['items'=>$items, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }

    /* ==== MARK READ (tek veya çoklu) ==== */
    private static function markRead(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        // id (tek) veya ids[] (çoklu)
        $ids = [];
        if (isset($p['id']))  $ids[] = (int)$p['id'];
        if (!empty($p['ids']) && is_array($p['ids'])) {
            foreach ($p['ids'] as $x) $ids[] = (int)$x;
        }
        $ids = array_values(array_unique(array_filter($ids, fn($x)=>$x>0)));
        if (empty($ids)) return ['updated'=>false, 'error'=>'id_or_ids_required'];

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        array_unshift($params, $userId); // ilk param user_id
        $sql = "UPDATE company_notifications
                   SET is_read=1, read_at=NOW()
                 WHERE user_id = ?
                   AND id IN ($ph)";
        $ok = $crud->query($sql, $params) !== false;
        return ['updated'=>(bool)$ok];
    }

    private static function markAllRead(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $ok = $crud->update('company_notifications', [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ], ['user_id' => $userId]);
        return ['updated'=>(bool)$ok];
    }

    /* ==== JOB FANOUT (Router'dan da çağrılabilir) ==== */
    private static function pushJobPost(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $jobId = (int)($p['job_id'] ?? 0);
        if ($jobId <= 0) return ['pushed'=>false, 'error'=>'job_id_required'];

        // İzni doğrula (ilan sahibi/şirket admini/özel izin)
        $job = $crud->read('job_posts', ['id'=>$jobId], ['id','company_id','title','visibility'], false);
        if (!$job) return ['pushed'=>false, 'error'=>'job_not_found'];

        $cid = (int)$job['company_id'];
        $can = false;
        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            $can = PermissionService::hasPermission($userId, 'job.update', $cid) ||
                   PermissionService::hasPermission($userId, 'job.publish', $cid);
        }
        if (!$can) return ['pushed'=>false, 'error'=>'not_authorized'];

        $count = self::fanoutFollowers($crud, $jobId);
        return ['pushed'=>true, 'count'=>$count];
    }

    /* ==== INTERNAL: JobHandler çağırır (Auth gerektirmez) ==== */
    public static function push_job_post_internal(int $jobId): int
    {
        $crud = new Crud(); // internal işlem, public
        return self::fanoutFollowers($crud, $jobId);
    }

    /* ==== ortak fanout ==== */
    private static function fanoutFollowers(Crud $crud, int $jobId): int
    {
        // Job + Company bilgisi
        $job = $crud->query("
            SELECT j.id, j.title, j.company_id, j.visibility, j.area, j.location,
                   c.name AS company_name
            FROM job_posts j
            JOIN companies c ON c.id = j.company_id
            WHERE j.id = :j
            LIMIT 1
        ", [':j'=>$jobId]);
        if (!$job) return 0;
        $j = $job[0];

        // Sadece takipçilere gönder: visibility 'followers' ise zorunlu, 'public' ise opsiyonel (yine takipçilere)
        // Burada iki durumu da takipçilere gönderiyoruz.
        $title = "Yeni ilan: " . (string)$j['title'];
        $body  = (string)$j['company_name'];
        if (!empty($j['area']))     $body .= " • " . $j['area'];
        if (!empty($j['location'])) $body .= " • " . $j['location'];

        $meta = json_encode([
            'kind'       => 'job_post',
            'job_id'     => (int)$j['id'],
            'company_id' => (int)$j['company_id'],
            'visibility' => (string)$j['visibility'],
            'area'       => (string)$j['area'],
            'location'   => (string)$j['location'],
        ], JSON_UNESCAPED_UNICODE);

        // Sadece app bildirimi açık olanlara gönder (users.app_company_notifications = 1)
        // (Email için MailHandler ileride eklenir.)
        $sql = "
            INSERT IGNORE INTO company_notifications
                (user_id, type, title, body, meta, dedupe_key, created_at)
            SELECT f.user_id, 'job', :title, :body, :meta,
                   CONCAT('job:', :jid, ':', f.user_id) AS dedupe_key,
                   NOW()
            FROM company_followers f
            JOIN users u ON u.id = f.user_id
            WHERE f.company_id = :cid
              AND f.unfollow IS NULL
              AND (u.app_company_notifications IS NULL OR u.app_company_notifications = 1)
        ";
        $ok = $crud->query($sql, [
            ':title' => $title,
            ':body'  => $body,
            ':meta'  => $meta,
            ':jid'   => (int)$j['id'],
            ':cid'   => (int)$j['company_id'],
        ]);

        // Crud::query SELECT dönerken fetchAll yapıyor; INSERT için dönüş false olabilir.
        // Başarı sayısını almak için ayrıca etkilenen satır sayısını ölçemiyoruz.
        // Pratik: Bildirim sayısını tahmin etmek için follower sayısını alalım.
        $rows = $crud->query("
            SELECT COUNT(*) AS cnt
            FROM company_followers f
            JOIN users u ON u.id = f.user_id
            WHERE f.company_id = :cid
              AND f.unfollow IS NULL
              AND (u.app_company_notifications IS NULL OR u.app_company_notifications = 1)
        ", [':cid'=>(int)$j['company_id']]);

        return (int)($rows[0]['cnt'] ?? 0);
    }
}
if (!class_exists('CompanynotificationHandler')) {
    class CompanynotificationHandler extends CompanyNotificationHandler {}
}
