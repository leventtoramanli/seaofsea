<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/FileHandler.php';
require_once __DIR__ . '/companynotificationhandler.php';

class JobHandler
{
    public static function apply_job(array $p=[]): array { return self::apply($p); }
    public static function create(array $p = []): array  { return self::createJob($p); }
    public static function publish(array $p = []): array { return self::publishJob($p); }
    public static function close(array $p = []): array   { return self::closeJob($p); }
    public static function list(array $p = []): array    { return self::listJobs($p); }
    public static function detail(array $p = []): array  { return self::detailJob($p); }
    public static function my_applications(array $p = []): array { return self::myApplications($p); }
    public static function applications(array $p = []): array    { return self::applicationsForCompanyOrJob($p); }
    public static function application_update_status(array $p = []): array { return self::updateApplicationStatus($p); }
    public static function application_withdraw(array $p = []): array      { return self::withdrawApplication($p); }
    public static function search(array $p = []): array   { return self::searchJobs($p); }
    public static function update(array $p = []): array  { return self::updateJob($p); }
    public static function archive(array $p = []): array { return self::archiveJob($p); }
    public static function reopen(array $p = []): array  { return self::reopenJob($p); }
    public static function delete(array $p = []): array  { return self::softDeleteJob($p); }
    public static function undelete(array $p = []): array{ return self::unDeleteJob($p); }

    /* ==== APPLICATION: New application fanout (internal) ==== */
    public static function push_application_new_internal(int $jobId, int $applicantUserId): int
    {
        $crud = new Crud(); // internal call; auth gerekmiyor

        // Job + Company + Applicant
        $jobRow = $crud->query("
            SELECT j.id, j.title, j.company_id, c.name AS company_name, j.created_by
            FROM job_posts j
            JOIN companies c ON c.id = j.company_id
            WHERE j.id = :jid
            LIMIT 1
        ", [':jid'=>$jobId]);
        if (!$jobRow) return 0;
        $j = $jobRow[0];

        $userRow = $crud->read('users', ['id'=>$applicantUserId], ['id','name','surname'], false);
        $applicantName = $userRow ? trim(($userRow['name'] ?? '').' '.($userRow['surname'] ?? '')) : 'Aday';

        $title = "Yeni başvuru";
        $body  = $j['company_name'] . " • " . $j['title'] . " • " . $applicantName;

        $meta = json_encode([
            'kind'         => 'application_new',
            'job_id'       => (int)$j['id'],
            'company_id'   => (int)$j['company_id'],
            'applicant_id' => (int)$applicantUserId,
            'status'       => 'submitted',
        ], JSON_UNESCAPED_UNICODE);

        $cid = (int)$j['company_id'];
        $uid = (int)$applicantUserId;

        // 1) Şirket adminlerine gönder (kullanıcının kendi başvurusunda kendisine bildirim gitmesin)
        $crud->query("
            INSERT IGNORE INTO company_notifications
                (user_id, type, title, body, meta, dedupe_key, created_at)
            SELECT cu.user_id, 'job', :title, :body, :meta,
                CONCAT('app:new:', :jid, ':', :app, ':', cu.user_id),
                NOW()
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            JOIN users u ON u.id = cu.user_id
            WHERE cu.company_id = :cid
            AND r.scope = 'company'
            AND r.name  = 'admin'
            AND cu.user_id <> :app
            AND (u.app_company_notifications IS NULL OR u.app_company_notifications = 1)
        ", [
            ':title'=>$title, ':body'=>$body, ':meta'=>$meta,
            ':jid'=>$jobId, ':app'=>$uid, ':cid'=>$cid
        ]);

        // 2) İlanı açan kullanıcıya (created_by) da gönder (admin değilse ve kendisi başvurmadıysa)
        $crud->query("
            INSERT IGNORE INTO company_notifications
                (user_id, type, title, body, meta, dedupe_key, created_at)
            SELECT j.created_by, 'job', :title, :body, :meta,
                CONCAT('app:new:', :jid, ':', :app, ':', j.created_by),
                NOW()
            FROM job_posts j
            JOIN users u ON u.id = j.created_by
            WHERE j.id = :jid
            AND j.created_by IS NOT NULL
            AND j.created_by <> :app
            AND (u.app_company_notifications IS NULL OR u.app_company_notifications = 1)
        ", [
            ':title'=>$title, ':body'=>$body, ':meta'=>$meta,
            ':jid'=>$jobId, ':app'=>$uid
        ]);

        // Kaç kişiye gittiğini yaklaşık döndür (admin sayısı + creator)
        $cntAdmins = $crud->query("
            SELECT COUNT(*) AS c
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            JOIN users u ON u.id = cu.user_id
            WHERE cu.company_id = :cid
            AND r.scope = 'company'
            AND r.name  = 'admin'
            AND cu.user_id <> :app
            AND (u.app_company_notifications IS NULL OR u.app_company_notifications = 1)
        ", [':cid'=>$cid, ':app'=>$uid]);

        $cntCreator = 0;
        if (!empty($j['created_by']) && (int)$j['created_by'] !== $uid) {
            $okCreator = $crud->read('users', ['id'=>(int)$j['created_by']], ['id','app_company_notifications'], false);
            if ($okCreator && (is_null($okCreator['app_company_notifications']) || (int)$okCreator['app_company_notifications'] === 1)) {
                $cntCreator = 1;
            }
        }

        return (int)($cntAdmins[0]['c'] ?? 0) + $cntCreator;
    }

    /* ==== APPLICATION: Status change -> notify applicant (internal) ==== */
    public static function push_application_status_internal(int $jobId, int $applicantUserId, string $status): int
    {
        $crud = new Crud();

        // normalize + etiket
        $allowed = ['submitted','under_review','shortlisted','rejected','withdrawn','hired'];
        $status  = strtolower(trim($status));
        if (!in_array($status, $allowed, true)) $status = 'submitted';

        $labels = [
            'submitted'    => 'Başvuru alındı',
            'under_review' => 'İncelemede',
            'shortlisted'  => 'Kısa liste',
            'rejected'     => 'Reddedildi',
            'withdrawn'    => 'Geri çekildi',
            'hired'        => 'İşe alındı',
        ];
        $statusLabel = $labels[$status] ?? ucfirst($status);

        // Job + Company
        $jobRow = $crud->query("
            SELECT j.id, j.title, j.company_id, c.name AS company_name
            FROM job_posts j
            JOIN companies c ON c.id = j.company_id
            WHERE j.id = :jid
            LIMIT 1
        ", [':jid'=>$jobId]);
        if (!$jobRow) return 0;
        $j = $jobRow[0];

        $title = "Başvuru durumu: " . $statusLabel;
        $body  = $j['company_name'] . " • " . $j['title'];

        $meta = json_encode([
            'kind'       => 'application_status',
            'job_id'     => (int)$j['id'],
            'company_id' => (int)$j['company_id'],
            'status'     => $status,
        ], JSON_UNESCAPED_UNICODE);

        // Kullanıcı tercihine bak (varsayılan açık)
        $u = $crud->read('users', ['id'=>$applicantUserId], ['id','app_company_notifications'], false);
        if (!$u) return 0;
        if (!(is_null($u['app_company_notifications']) || (int)$u['app_company_notifications'] === 1)) {
            return 0; // kullanıcı bildirimleri kapatmış
        }

        // Insert IGNORE (aynı statü tekrar bildirilmesin)
        $crud->query("
            INSERT IGNORE INTO company_notifications
                (user_id, type, title, body, meta, dedupe_key, created_at)
            VALUES
                (:uid, 'job', :title, :body, :meta,
                CONCAT('app:status:', :jid, ':', :uid, ':', :st), NOW())
        ", [
            ':uid'=>(int)$applicantUserId,
            ':title'=>$title,
            ':body'=>$body,
            ':meta'=>$meta,
            ':jid'=>$jobId,
            ':st'=>$status,
        ]);

        return 1;
    }
    private static function createJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $companyId  = (int)($p['company_id'] ?? 0);
        $title      = trim((string)($p['title'] ?? ''));
        $positionId = isset($p['position_id']) ? (int)$p['position_id'] : null;
        $desc       = $p['description'] ?? null;
        $req        = $p['requirements'] ?? null; // array|string
        $area       = in_array(($p['area'] ?? 'crew'), ['crew','office'], true) ? $p['area'] : 'crew';
        $location   = $p['location'] ?? null;
        $visibility = in_array(($p['visibility'] ?? 'public'), ['public','followers','private'], true) ? $p['visibility'] : 'public';

        if ($companyId <= 0 || $title === '') {
            return ['created'=>false, 'error'=>'company_id_and_title_required'];
        }

        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            if (!PermissionService::hasPermission($userId, 'job.create', $companyId)) {
                return ['created'=>false, 'error'=>'not_authorized'];
            }
        }

        $payload = [
            'company_id'  => $companyId,
            'position_id' => $positionId ?: null,
            'title'       => $title,
            'description' => is_string($desc) ? $desc : (is_array($desc) ? json_encode($desc, JSON_UNESCAPED_UNICODE) : null),
            'requirements'=> is_array($req) ? json_encode($req, JSON_UNESCAPED_UNICODE) : (is_string($req) ? $req : null),
            'area'        => $area,
            'location'    => $location ?: null,
            'visibility'  => $visibility,
            'status'      => 'open',
            'created_by'  => $userId,
            'opened_at'   => date('Y-m-d H:i:s'),
        ];

        $jid = $crud->create('job_posts', array_filter($payload, fn($v)=>$v!==null));
        if (!$jid) return ['created'=>false, 'error'=>'db_create_failed'];

        $notifyFollowers = (int)($p['notify_followers'] ?? 0) === 1;
        if ($visibility === 'followers' || $notifyFollowers) {
            if (class_exists('CompanyNotificationHandler') && method_exists('CompanyNotificationHandler','push_job_post_internal')) {
                CompanyNotificationHandler::push_job_post_internal((int)$jid); // <-- düzeltildi
            }
        }

        return ['created'=>true, 'id'=>(int)$jid];
    }
    private static function publishJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['published'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','status'], false);
        if (!$job) return ['published'=>false, 'error'=>'not_found'];

        $allowed = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? PermissionService::hasPermission($userId, 'job.publish', (int)$job['company_id'])
            : false;
        if (!$allowed) return ['published'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'status'   => 'open',
            'opened_at'=> date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['published'=>(bool)$ok, 'id'=>$id];
    }

    private static function closeJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['closed'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','status'], false);
        if (!$job) return ['closed'=>false, 'error'=>'not_found'];

        $allowed = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? PermissionService::hasPermission($userId, 'job.close', (int)$job['company_id'])
            : false;
        if (!$allowed) return ['closed'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'status'   => 'closed',
            'closed_at'=> date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['closed'=>(bool)$ok, 'id'=>$id];
    }
    private static function listJobs(array $p): array
    {
        $auth = Auth::check();
        $uid  = $auth ? (int)$auth['user_id'] : null;
        $crud = $uid ? new Crud($uid) : new Crud();

        $companyId  = isset($p['company_id']) ? (int)$p['company_id'] : null;
        $status     = $p['status'] ?? 'open';
        $visibility = $p['visibility'] ?? null; // opsiyonel: public|followers|private

        // sayfalama
        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        // temel WHERE
        $where  = [];
        $params = [];
        if ($companyId) { $where[] = "j.company_id = :c"; $params[':c'] = $companyId; }
        if ($status)    { $where[] = "j.status = :s";     $params[':s'] = $status;    }
        $whereSql = $where ? (" WHERE ".implode(" AND ", $where)." AND j.deleted_at IS NULL") : "WHERE j.deleted_at IS NULL";

        // ham liste (görünürlük süzmesi uygulayacağımız için güvenli üst sınırla alıyoruz)
        $rows = $crud->query("
            SELECT j.*
            FROM job_posts j
            {$whereSql}
            ORDER BY j.created_at DESC
            LIMIT 1000
        ", $params) ?: [];

        // görünürlük süzme
        $out = [];
        foreach ($rows as $row) {
            $vis = $row['visibility'] ?? 'public';
            $cid = (int)$row['company_id'];

            if ($vis === 'public') { $out[] = $row; continue; }

            if ($vis === 'followers') {
                if ($uid) {
                    $isFollower = (bool)$crud->query("
                        SELECT 1 FROM company_followers
                        WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
                        LIMIT 1
                    ", [':c'=>$cid, ':u'=>$uid]);
                    if ($isFollower) $out[] = $row;
                }
                continue;
            }

            if ($vis === 'private') {
                if ($uid && class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
                    && PermissionService::hasPermission($uid, 'job.update', $cid)) {
                    $out[] = $row;
                }
                continue;
            }
        }

        // opsiyonel visibility filtresi
        if ($visibility && in_array($visibility, ['public','followers','private'], true)) {
            $out = array_values(array_filter($out, fn($r)=> ($r['visibility'] ?? 'public') === $visibility));
        }

        $total = count($out);
        $items = array_slice($out, $offset, $perPage);

        return ['items'=>$items, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }

    private static function detailJob(array $p): array
    {
        $auth = Auth::check();
        $uid  = $auth ? (int)$auth['user_id'] : null;
        $crud = $uid ? new Crud($uid) : new Crud();

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['found'=>false, 'error'=>'id_required'];

        $row = $crud->read('job_posts', ['id'=>$id], ['*'], false);
        if (!$row) return ['found'=>false, 'error'=>'not_found'];
        if (!empty($row['deleted_at'])) return ['found'=>false, 'error'=>'not_found'];

        $vis = $row['visibility'] ?? 'public';
        $cid = (int)$row['company_id'];

        if ($vis === 'public') return $row;

        if ($vis === 'followers') {
            if (!$uid) return ['found'=>false, 'error'=>'auth_required'];
            $isFollower = (bool)$crud->query("
                SELECT 1 FROM company_followers
                WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
                LIMIT 1
            ", [':c'=>$cid, ':u'=>$uid]);
            if ($isFollower) return $row;
            return ['found'=>false, 'error'=>'not_authorized'];
        }

        if ($vis === 'private') {
            if (!$uid) return ['found'=>false, 'error'=>'auth_required'];
            if (PermissionService::hasPermission($uid, 'job.update', $cid)) return $row;
            return ['found'=>false, 'error'=>'not_authorized'];
        }

        return ['found'=>false];
    }
    private static function apply(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $jobId   = isset($p['job_id']) ? (int)$p['job_id'] : 0; // yeni
        $message = trim((string)($p['message'] ?? ''));

        // ✅ Yeni akış: job_id varsa ilan üstünden başvuru
        if ($jobId > 0) {
            // İlanı çek
            $job = $crud->read('job_posts', ['id' => $jobId], ['id','company_id','status','visibility'], false);
            if (!$job) return ['success'=>false, 'error'=>'job_not_found'];

            $companyId  = (int)$job['company_id'];
            $visibility = (string)$job['visibility'];
            $status     = (string)$job['status'];

            if ($status !== 'open') {
                return ['success'=>false, 'error'=>'job_not_open'];
            }

            // Görünürlük kuralı (detail ile aynı mantık)
            if ($visibility === 'followers') {
                $isFollower = (bool)$crud->query("
                    SELECT 1 FROM company_followers
                    WHERE company_id = :c AND user_id = :u AND unfollow IS NULL
                    LIMIT 1
                ", [':c'=>$companyId, ':u'=>$userId]);
                if (!$isFollower) return ['success'=>false, 'error'=>'not_authorized_followers_only'];
            } elseif ($visibility === 'private') {
                if (!(class_exists('PermissionService') && PermissionService::hasPermission($userId, 'job.update', $companyId))) {
                    return ['success'=>false, 'error'=>'not_authorized_private'];
                }
            }

            // Aynı ilana aktif başvuru var mı? (withdrawn/rejected dışında)
            $dup = $crud->query("
                SELECT 1 FROM job_applications
                WHERE job_id = :j AND user_id = :u AND status NOT IN ('withdrawn','rejected')
                LIMIT 1
            ", [':j'=>$jobId, ':u'=>$userId]);
            if ($dup) {
                return ['success'=>false, 'error'=>'already_applied'];
            }

            // CV snapshot (user_cv tablosundan)
            $cvRow = $crud->read('user_cv', ['user_id' => $userId], ['*'], false);
            $cvSnap = null;
            if ($cvRow) {
                // İstediğimiz başlıkları toparlayalım
                $cvSnapArr = [
                    'professional_title' => $cvRow['professional_title'] ?? null,
                    'basic_info'         => $cvRow['basic_info'] ?? null,
                    'language'           => self::jsonMaybe($cvRow['language'] ?? null),
                    'education'          => self::jsonMaybe($cvRow['education'] ?? null),
                    'work_experience'    => self::jsonMaybe($cvRow['work_experience'] ?? null),
                    'skills'             => self::jsonMaybe($cvRow['skills'] ?? null),
                    'certificates'       => self::jsonMaybe($cvRow['certificates'] ?? null),
                    'seafarer_info'      => self::jsonMaybe($cvRow['seafarer_info'] ?? null),
                    'references'         => self::jsonMaybe($cvRow['references'] ?? null),
                ];
                $cvSnap = json_encode($cvSnapArr, JSON_UNESCAPED_UNICODE);
            }

            // Dosya(lar)
            $attachments = [];
            $savedFileLegacy = null;
            if (!empty($p['file_name']) && !empty($p['file_data'])) {
                $fn  = basename((string)$p['file_name']);
                $raw = base64_decode((string)$p['file_data'], true);
                if ($raw !== false) {
                    $folder = 'job_applications';
                    $fh = new FileHandler();
                    $path = $fh->createFolderPath($folder) . $fn;
                    file_put_contents($path, $raw);
                    $attachments[] = $fn;
                    $savedFileLegacy = $fn; // backwards-compat
                }
            }
            if (!empty($p['attachments']) && is_array($p['attachments'])) {
                $fh = new FileHandler();
                $dir = $fh->createFolderPath('job_applications');
                if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

                foreach ($p['attachments'] as $item) {
                    $orig2 = trim((string)($item['file_name'] ?? ''));
                    $raw2  = base64_decode((string)($item['file_data_base64'] ?? ''), true);
                    if ($orig2 !== '' && $raw2 !== false) {
                        $ext2 = strtolower(pathinfo($orig2, PATHINFO_EXTENSION));
                        $allowed = ['pdf','jpg','jpeg','png','webp'];
                        if (in_array($ext2, $allowed, true)) {
                            $fn2 = 'app_' . uniqid('', true) . '.' . $ext2;
                            $path2 = $dir . $fn2;
                            if (file_put_contents($path2, $raw2) !== false) {
                                $attachments[] = $fn2;
                            }
                        }
                    }
                }
            }

            $aid = $crud->create('job_applications', [
                'job_id'      => $jobId,
                'company_id'  => $companyId,
                'user_id'     => $userId,
                'status'      => 'submitted',
                'message'     => $message !== '' ? $message : null,
                'cv_snapshot' => $cvSnap,
                'attachments' => $attachments ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null,
                'file_name'   => $savedFileLegacy, // legacy alan
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            if (!$aid) return ['success'=>false, 'error'=>'db_create_failed'];
            // Şirket adminlerine bildirim gönder
            if (class_exists('CompanyNotificationHandler') && method_exists('CompanyNotificationHandler','push_application_new_internal')) {
                CompanyNotificationHandler::push_application_new_internal($jobId, $userId);
            }
            return ['success'=>true, 'id'=>(int)$aid];
        }

        /*// 🔙 Legacy akış (job_id yoksa): sadece serbest metin "position" alanı
        $position = trim((string)($p['position'] ?? ''));
        if ($position === '') return ['success'=>false, 'error'=>'position_required'];

        $savedFileLegacy = null;
        if (!empty($p['file_name']) && !empty($p['file_data'])) {
            $origName = trim((string)$p['file_name']);
            $raw = base64_decode((string)$p['file_data'], true);

            if ($raw !== false && $origName !== '') {
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowed = ['pdf','jpg','jpeg','png','webp'];
                if (in_array($ext, $allowed, true)) {
                    $fh = new FileHandler();
                    $dir = $fh->createFolderPath('job_applications');
                    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
                    $fn = 'app_' . uniqid('', true) . '.' . $ext;
                    $path = $dir . $fn;
                    if (file_put_contents($path, $raw) !== false) {
                        $attachments[] = $fn;
                        $savedFileLegacy = $fn; // legacy kolon için
                    }
                }
            }
        }

        $crud->create('job_applications', [
            'user_id'    => $userId,
            'position'   => $position, // legacy kolonu yoksa sorun değil; sütun ekli değilse DB ignore etmez -> bu yolu yeni tabloda kullanma
            'message'    => $message,
            'file_name'  => $savedFile,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success'=>true];*/
        return ['success'=>false, 'error'=>'job_id_required'];
    }
    private static function jsonMaybe($v) {
        if (is_string($v)) {
            $j = json_decode($v, true);
            return is_array($j) ? $j : $v;
        }
        return $v;
    }
    private static function myApplications(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $status  = $p['status'] ?? null;
        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        // total
        $sqlCount = "
            SELECT COUNT(*) AS c
            FROM job_applications a
            JOIN job_posts j ON j.id = a.job_id
            WHERE a.user_id = :u
            ".($status ? " AND a.status = :s" : "")."
        ";
        $params = [':u'=>$userId];
        if ($status) $params[':s'] = $status;

        $rowC  = $crud->query($sqlCount, $params);
        $total = (int)($rowC[0]['c'] ?? 0);

        // items
        $sql = "
            SELECT a.id, a.status, a.created_at, a.updated_at,
                j.id AS job_id, j.title, j.company_id,
                c.name AS company_name, j.area, j.location
            FROM job_applications a
            JOIN job_posts j ON j.id = a.job_id
            JOIN companies  c ON c.id = j.company_id
            WHERE a.user_id = :u
            ".($status ? " AND a.status = :s" : "")."
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $paramsItems = $params + [':limit'=>$perPage, ':offset'=>$offset];

        // bind int tipleri için küçük helper: Crud->query bind tipi yok; o yüzden read yerine prepare etmiyoruz.
        // Çoğu PDO sürümü LIMIT/OFFSET parametreli çalışır; sorun çıkarsa fallback yaparız.
        $items = $crud->query($sql, $paramsItems) ?: [];

        return ['items'=>$items, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }
    private static function applicationsForCompanyOrJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $actor  = (int)$auth['user_id'];
        $crud   = new Crud($actor);

        $companyId = isset($p['company_id']) ? (int)$p['company_id'] : null;
        $jobId     = isset($p['job_id']) ? (int)$p['job_id'] : null;
        $status    = $p['status'] ?? null;

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        if (!$companyId && !$jobId) {
            return ['items'=>[], 'error'=>'company_id_or_job_id_required', 'page'=>$page, 'perPage'=>$perPage, 'total'=>0];
        }
        if (!$companyId && $jobId) {
            $row = $crud->read('job_posts', ['id'=>$jobId], ['company_id'], false);
            if (!$row) return ['items'=>[], 'error'=>'job_not_found', 'page'=>$page, 'perPage'=>$perPage, 'total'=>0];
            $companyId = (int)$row['company_id'];
        }

        // Yetki: company admin || job.applications.view
        $isAdmin = (bool)$crud->query("
            SELECT 1
            FROM company_users cu
            JOIN roles r ON r.id = cu.role_id
            WHERE cu.user_id = :u AND cu.company_id = :c
            AND r.scope = 'company' AND r.name = 'admin'
            LIMIT 1
        ", [':u'=>$actor, ':c'=>$companyId]);

        $hasPerm = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? PermissionService::hasPermission($actor, 'job.applications.view', $companyId)
            : false;

        if (!($isAdmin || $hasPerm)) {
            return ['items'=>[], 'error'=>'not_authorized', 'page'=>$page, 'perPage'=>$perPage, 'total'=>0];
        }

        // COUNT
        $sqlCount = "
            SELECT COUNT(*) AS c
            FROM job_applications a
            JOIN job_posts j ON j.id = a.job_id
            JOIN users u ON u.id = a.user_id
            WHERE a.company_id = :c
            ".($jobId ? " AND a.job_id = :j" : "")."
            ".($status ? " AND a.status = :s" : "")."
        ";
        $params = [':c'=>$companyId];
        if ($jobId)  $params[':j'] = $jobId;
        if ($status) $params[':s'] = $status;

        $rowC  = $crud->query($sqlCount, $params);
        $total = (int)($rowC[0]['c'] ?? 0);

        // ITEMS
        $sql = "
            SELECT a.id, a.status, a.created_at, a.updated_at, a.user_id,
                u.name, u.surname, u.email, u.user_image,
                j.id AS job_id, j.title
            FROM job_applications a
            JOIN job_posts j ON j.id = a.job_id
            JOIN users u ON u.id = a.user_id
            WHERE a.company_id = :c
            ".($jobId ? " AND a.job_id = :j" : "")."
            ".($status ? " AND a.status = :s" : "")."
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $paramsItems = $params + [':limit'=>$perPage, ':offset'=>$offset];

        $items = $crud->query($sql, $paramsItems) ?: [];

        return ['items'=>$items, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }
    private static function updateApplicationStatus(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $appId  = (int)($p['application_id'] ?? $p['id'] ?? 0);
        $status = (string)($p['status'] ?? '');
        $allowed = ['submitted','under_review','shortlisted','rejected','withdrawn','hired'];

        if ($appId <= 0) return ['updated'=>false, 'error'=>'application_id_required'];
        if (!in_array($status, $allowed, true)) return ['updated'=>false, 'error'=>'invalid_status'];

        // Başvuruyu ve şirketini bul
        $a = $crud->read('job_applications', ['id'=>$appId], ['id','job_id','company_id','user_id','status'], false);
        if (!$a) return ['updated'=>false, 'error'=>'not_found'];

        // Yetki
        $companyId = (int)$a['company_id'];
        $authorized = false;
        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            $authorized = PermissionService::hasPermission($userId, 'application.review', $companyId)
                    || PermissionService::hasPermission($userId, 'job.update', $companyId);
        }
        if (!$authorized) return ['updated'=>false, 'error'=>'not_authorized'];

        if($ok){
            self::push_application_status_internal(
                (int)$a['job_id'], (int)$a['user_id'], $status
            );
        }
        return ['updated'=>(bool)$ok, 'id'=>$appId, 'status'=>$status];
    }
    private static function withdrawApplication(array $p): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud   = new Crud($userId);

        $appId = (int)($p['application_id'] ?? $p['id'] ?? 0);
        if ($appId <= 0) return ['withdrawn'=>false, 'error'=>'application_id_required'];

        $a = $crud->read('job_applications', ['id'=>$appId], ['id','user_id','status'], false);
        if (!$a) return ['withdrawn'=>false, 'error'=>'not_found'];
        if ((int)$a['user_id'] !== $userId) return ['withdrawn'=>false, 'error'=>'not_owner'];

        // Kapalı/sonuçlanmış başvuru çekilemeyebilir (opsiyonel kural)
        if (in_array($a['status'], ['rejected','hired'], true)) {
            return ['withdrawn'=>false, 'error'=>'finalized'];
        }

        $ok = $crud->update('job_applications', [
            'status'     => 'withdrawn',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$appId]);

        return ['withdrawn'=>(bool)$ok, 'id'=>$appId];
    }
    private static function searchJobs(array $p): array
    {
        $auth = Auth::check();
        $uid  = $auth ? (int)$auth['user_id'] : null;
        $crud = $uid ? new Crud($uid) : new Crud();

        $q             = trim((string)($p['q'] ?? ''));
        $companyId     = isset($p['company_id'])  ? (int)$p['company_id']  : null;
        $positionId    = isset($p['position_id']) ? (int)$p['position_id'] : null;
        $area          = in_array(($p['area'] ?? ''), ['crew','office'], true) ? $p['area'] : null;
        $status        = ($p['status'] ?? 'open');
        $visibilityF   = $p['visibility'] ?? null;
        $followingOnly = (int)($p['following_only'] ?? 0) === 1;

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(100, (int)($p['perPage'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        // takip edilenler
        $followed = [];
        if ($uid) {
            $rowsF = $crud->query("
                SELECT company_id FROM company_followers
                WHERE user_id = :u AND unfollow IS NULL
            ", [':u'=>$uid]) ?: [];
            foreach ($rowsF as $r) $followed[(int)$r['company_id']] = true;
        }

        // filtreler
        $where  = [];
        $params = [];
        if ($companyId)  { $where[] = "j.company_id = :company_id";   $params[':company_id']  = $companyId; }
        if ($positionId) { $where[] = "j.position_id = :position_id"; $params[':position_id'] = $positionId; }
        if ($area)       { $where[] = "j.area = :area";               $params[':area']        = $area; }
        if ($status && $status !== 'any') { $where[] = "j.status = :status"; $params[':status'] = $status; }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where) . ' AND j.deleted_at IS NULL') : ' WHERE j.deleted_at IS NULL';

        // takip filtresi
        $joinFollow = '';
        if ($followingOnly && $uid) {
            $joinFollow = " JOIN company_followers f
                            ON f.company_id = j.company_id
                        AND f.user_id    = :uid
                        AND f.unfollow  IS NULL ";
            $params[':uid'] = $uid;
        }

        // arama
        if (mb_strlen($q) >= 2) {
            $qBoolean = implode(' ', array_map(fn($w)=> (strlen($w)>=2 ? $w.'*' : $w), preg_split('/\s+/', $q)));
            $paramsFT = $params + [':q' => $qBoolean];

            // FULLTEXT: sadece title, description
            $sqlFT = "SELECT j.*, MATCH(j.title, j.description) AGAINST (:q IN BOOLEAN MODE) AS _score
                    FROM job_posts j
                    {$joinFollow}
                    {$whereSql}
                    AND MATCH(j.title, j.description) AGAINST (:q IN BOOLEAN MODE)
                    ORDER BY _score DESC, j.created_at DESC
                    LIMIT 1000";
            $rows = $crud->query($sqlFT, $paramsFT);

            // FT hata verirse LIKE fallback
            if ($rows === false) {
                $like = '%'.$q.'%';
                $paramsLike = $params + [':ql'=>$like];
                $whereLike = $whereSql.' '.(empty($whereSql) ? ' WHERE ' : ' AND ')
                        . "(j.title LIKE :ql OR j.description LIKE :ql OR j.requirements LIKE :ql)";
                $rows = $crud->query("
                    SELECT j.* FROM job_posts j
                    {$joinFollow}
                    {$whereLike}
                    ORDER BY j.created_at DESC
                    LIMIT 1000
                ", $paramsLike) ?: [];
            }
        } else {
            // q yoksa default liste
            $rows = $crud->query("
                SELECT j.* FROM job_posts j
                {$joinFollow}
                {$whereSql}
                ORDER BY j.created_at DESC
                LIMIT 1000
            ", $params) ?: [];
        }

        // görünürlük süzme
        $filtered = [];
        foreach ($rows as $r) {
            $vis = $r['visibility'] ?? 'public';
            $cid = (int)$r['company_id'];

            if ($vis === 'public') { $filtered[] = $r; continue; }
            if ($vis === 'followers') {
                if ($uid && isset($followed[$cid])) $filtered[] = $r;
                continue;
            }
            if ($vis === 'private') {
                if ($uid && class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
                    && PermissionService::hasPermission($uid, 'job.update', $cid)) {
                    $filtered[] = $r;
                }
                continue;
            }
        }

        if ($visibilityF && in_array($visibilityF, ['public','followers','private'], true)) {
            $filtered = array_values(array_filter($filtered, fn($x)=> ($x['visibility'] ?? 'public') === $visibilityF));
        }

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $perPage);

        return ['items'=>$items, 'page'=>$page, 'perPage'=>$perPage, 'total'=>$total];
    }
    private static function updateJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $uid    = (int)$auth['user_id'];
        $crud   = new Crud($uid);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['updated'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','deleted_at'], false);
        if (!$job) return ['updated'=>false, 'error'=>'not_found'];
        if (!empty($job['deleted_at'])) return ['updated'=>false, 'error'=>'deleted'];

        $cid = (int)$job['company_id'];
        if (class_exists('PermissionService') && method_exists('PermissionService','hasPermission')) {
            if (!PermissionService::hasPermission($uid, 'job.update', $cid)) {
                return ['updated'=>false, 'error'=>'not_authorized'];
            }
        }

        // İzin verilen alanlar
        $allowed = ['title','description','requirements','area','location','visibility','position_id'];
        $data = [];

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $p)) continue;
            $v = $p[$k];

            if ($k === 'area') {
                $v = in_array(($v ?? ''), ['crew','office'], true) ? $v : null;
                if ($v === null) continue;
            }
            if ($k === 'visibility') {
                $v = in_array(($v ?? ''), ['public','followers','private'], true) ? $v : null;
                if ($v === null) continue;
            }
            if ($k === 'position_id') {
                $v = isset($v) ? (int)$v : null;
            }
            if (in_array($k, ['description','requirements'], true)) {
                // string veya array kabul; array ise JSON’a çevir
                if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($v !== null && !is_string($v)) continue;
            }
            if ($k === 'title') {
                $v = trim((string)$v);
                if ($v === '') continue;
            }

            $data[$k] = $v;
        }

        if (!$data) return ['updated'=>false, 'error'=>'no_fields'];

        $data['updated_at'] = date('Y-m-d H:i:s');

        $ok = $crud->update('job_posts', $data, ['id'=>$id]);
        return ['updated'=>(bool)$ok, 'id'=>$id];
    }
    private static function archiveJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $uid    = (int)$auth['user_id'];
        $crud   = new Crud($uid);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['archived'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','deleted_at'], false);
        if (!$job) return ['archived'=>false, 'error'=>'not_found'];
        if (!empty($job['deleted_at'])) return ['archived'=>false, 'error'=>'deleted'];

        $cid = (int)$job['company_id'];
        $can = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? (PermissionService::hasPermission($uid, 'job.archive', $cid) || PermissionService::hasPermission($uid, 'job.update', $cid))
            : true;

        if (!$can) return ['archived'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'status'      => 'archived',
            'archived_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['archived'=>(bool)$ok, 'id'=>$id];
    }
    private static function reopenJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $uid    = (int)$auth['user_id'];
        $crud   = new Crud($uid);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['reopened'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','deleted_at'], false);
        if (!$job) return ['reopened'=>false, 'error'=>'not_found'];
        if (!empty($job['deleted_at'])) return ['reopened'=>false, 'error'=>'deleted'];

        $cid = (int)$job['company_id'];
        $can = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? (PermissionService::hasPermission($uid, 'job.publish', $cid) || PermissionService::hasPermission($uid, 'job.update', $cid))
            : true;

        if (!$can) return ['reopened'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'status'      => 'open',
            'opened_at'   => date('Y-m-d H:i:s'),
            'archived_at' => null,
            'closed_at'   => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['reopened'=>(bool)$ok, 'id'=>$id];
    }
    private static function softDeleteJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $uid    = (int)$auth['user_id'];
        $crud   = new Crud($uid);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['deleted'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id','deleted_at'], false);
        if (!$job) return ['deleted'=>false, 'error'=>'not_found'];
        if (!empty($job['deleted_at'])) return ['deleted'=>true, 'id'=>$id]; // idempotent

        $cid = (int)$job['company_id'];
        $can = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? (PermissionService::hasPermission($uid, 'job.delete', $cid) || PermissionService::hasPermission($uid, 'job.update', $cid))
            : true;

        if (!$can) return ['deleted'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['deleted'=>(bool)$ok, 'id'=>$id];
    }

    private static function unDeleteJob(array $p): array
    {
        $auth   = Auth::requireAuth();
        $uid    = (int)$auth['user_id'];
        $crud   = new Crud($uid);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) return ['undeleted'=>false, 'error'=>'id_required'];

        $job = $crud->read('job_posts', ['id'=>$id], ['id','company_id'], false);
        if (!$job) return ['undeleted'=>false, 'error'=>'not_found'];

        $cid = (int)$job['company_id'];
        $can = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? (PermissionService::hasPermission($uid, 'job.delete', $cid) || PermissionService::hasPermission($uid, 'job.update', $cid))
            : true;

        if (!$can) return ['undeleted'=>false, 'error'=>'not_authorized'];

        $ok = $crud->update('job_posts', [
            'deleted_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$id]);

        return ['undeleted'=>(bool)$ok, 'id'=>$id];
    }
}
