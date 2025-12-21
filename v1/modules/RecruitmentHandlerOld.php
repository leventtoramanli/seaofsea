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
    private static function sanitizeArea(?string $v): string {
        $v = trim((string)($v ?? 'crew'));
        $allowed = ['crew','office','port','shipyard','supplier','agency'];
        return in_array($v, $allowed, true) ? $v : 'crew';
    }
    private static function sanitizeEmployment(?string $v): ?string {
        return self::sanitize_enum($v, ['full_time','part_time','contract','seasonal','internship','temporary','other']);
    }
    private static function sanitizeSalaryUnit(?string $v): ?string {
        return self::sanitize_enum($v, ['hour','day','month','year','contract','trip']);
    }
    private static function validateAgeRange(?int $min, ?int $max): ?array {
        if ($min !== null && $min < 0) $min = 0;
        if ($max !== null && $max < 0) $max = 0;
        if ($min !== null && $max !== null && $min > $max) {
            return ['success'=>false,'message'=>'Age Min cannot be greater than Age Max','code'=>422];
        }
        return ['ok'=>['min'=>$min,'max'=>$max]];
    }
    private static function validateSalaryRange(?float $min, ?float $max): ?array {
        if ($min !== null && $min < 0) $min = 0.0;
        if ($max !== null && $max < 0) $max = 0.0;
        if ($min !== null && $max !== null && $min > $max) {
            return ['success'=>false,'message'=>'Salary Min cannot be greater than Salary Max','code'=>422];
        }
        return ['ok'=>['min'=>$min,'max'=>$max]];
    }
    private static function validateBonus(string $type, ?float $val): ?array {
        if ($type === 'fixed'   && ($val === null || $val <= 0))  return ['success'=>false,'message'=>'Rotation Bonus Value must be positive for fixed bonus','code'=>422];
        if ($type === 'percent' && ($val === null || $val < 0 || $val > 100)) return ['success'=>false,'message'=>'Rotation Bonus Value must be between 0 and 100 for percent bonus','code'=>422];
        return ['ok'=>true];
    }
    private static function normalizeJsonFields(array $p, array $keys): array {
        $out = [];
        foreach ($keys as $k) if (array_key_exists($k, $p)) $out[$k] = self::normalize_json($p[$k]);
        return $out;
    }

    private static function nowUtc(): string {
        return gmdate('Y-m-d H:i:s');
    }

    private static function ip(): ?string {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private static function ua(): ?string {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    private static function to_int_or_null($v): ?int {
        if ($v === null) return null;
        if ($v === '') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private static function to_decimal_or_null($v): ?float {
        if ($v === null) return null;
        if ($v === '') return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    }
    private static function sanitize_enum($v, array $whitelist): ?string {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        return in_array($v, $whitelist, true) ? $v : null;
    }

    // RecruitmentHandler.php (class içinde, en üste yakın bir yere)
    private static array $STATUS_META_CFG = [
        'submitted'     => ['label'=>'Submitted',     'color'=>'#64748B', 'icon'=>'inbox',          'terminal'=>false, 'sort'=>10],
        'under_review'  => ['label'=>'Under Review',  'color'=>'#3B82F6', 'icon'=>'search',         'terminal'=>false, 'sort'=>20],
        'shortlisted'   => ['label'=>'Shortlisted',   'color'=>'#6366F1', 'icon'=>'star',           'terminal'=>false, 'sort'=>30],
        'interview'     => ['label'=>'Interview',     'color'=>'#7C3AED', 'icon'=>'record_voice',   'terminal'=>false, 'sort'=>40],
        'offered'       => ['label'=>'Offered',       'color'=>'#14B8A6', 'icon'=>'workspace',      'terminal'=>false, 'sort'=>50],
        'hired'         => ['label'=>'Hired',         'color'=>'#16A34A', 'icon'=>'verified',       'terminal'=>true,  'sort'=>90],
        'rejected'      => ['label'=>'Rejected',      'color'=>'#EF4444', 'icon'=>'cancel',         'terminal'=>true,  'sort'=>95],
        'withdrawn'     => ['label'=>'Withdrawn',     'color'=>'#F59E0B', 'icon'=>'logout',         'terminal'=>true,  'sort'=>96],
    ];

    private static array $STATUS_TRANSITIONS_CFG = [
        // ← Burayı kendi iş kurallarına göre DOLDUR
        'submitted'    => ['under_review','withdrawn','rejected'],
        'under_review' => ['shortlisted','interview','rejected','withdrawn'],
        'shortlisted'  => ['interview','offered','rejected','withdrawn'],
        'interview'    => ['offered','rejected','withdrawn'],
        'offered'      => ['hired','rejected','withdrawn'],
        'hired'        => [],
        'rejected'     => [],
        'withdrawn'    => [],
    ];

    // Reset için BE kararı: tek bir hedef veya liste
    private static string $DEFAULT_RESET_TARGET = 'under_review';
    // (opsiyonel) farklı reset hedefleri
    private static array $RESET_TARGETS = ['under_review','submitted'];

    private static function sanitize_currency($v): ?string {
        if ($v === null) return null;
        $v = strtoupper(trim((string)$v));
        return (preg_match('/^[A-Z]{3}$/', $v)) ? $v : null;
    }

    private static function normalize_json($v): ?string {
        if ($v === null || $v === '') return null;
        if (is_string($v)) {
            json_decode($v, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $v : null;
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    private static function audit(Crud $crud, int $actorId, string $etype, int $eid, string $action, array $meta = []): void {
        try {
            $crud->create('audit_events', [
                'actor_id'    => $actorId,
                'entity_type' => $etype,
                'entity_id'   => $eid,
                'action'      => $action,
                'meta'        => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('AUDIT_FAIL '.$e->getMessage());
        }
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
        return ['view','draft','published','closed','archived'];
    }

    private static function allow_app_statuses(): array {
        return ['submitted','under_review','shortlisted','interview','offered','hired','rejected','withdrawn'];
    }

    private static function is_active_app_status(string $s): bool {
        return in_array($s, ['submitted','under_review','shortlisted','interview','offered'], true);
    }

        // === CV helpers ===
    private static function json_try_decode($v) {
        if ($v === null) return null;
        if (is_array($v)) return $v;
        if (!is_string($v)) return null;
        $d = json_decode($v, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $d : null;
    }

    public static function app_reset_status(array $p): array
    {
        $auth    = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud    = new Crud($actorId);

        $appId = (int)($p['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['success'=>false,'message'=>'application_id is required','code'=>422];
        }

        // target: 'under_review' | 'submitted' (default under_review)
        $target = trim((string)($p['target'] ?? 'under_review'));
        $allowTargets = ['under_review','submitted'];
        if (!in_array($target, $allowTargets, true)) {
            $target = 'under_review';
        }
        $reason = trim((string)($p['reason'] ?? ''));
        $expected = isset($p['expected_status']) ? (string)$p['expected_status'] : null;

        // Uygulama + yetki
        $row = $crud->read('applications', ['id'=>$appId], ['id','company_id','status'], false);
        if (!$row) return ['success'=>false,'message'=>'Application not found','code'=>404];

        $companyId = (int)$row['company_id'];
        Gate::check('recruitment.app.status_reset', $companyId);

        $old = (string)$row['status'];
        if ($expected !== null && $expected !== $old) {
            return ['success'=>false,'message'=>'Status changed by someone else. Reload.','code'=>409];
        }

        // Güncelle
        $ok = $crud->update('applications', [
            'status'     => $target,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id'=>$appId]);

        if ($ok === false) {
            return ['success'=>false,'message'=>'Reset failed','code'=>500];
        }

        // History
        $histNote = $reason !== '' ? ('Reset: '.$reason) : 'Reset';
        $crud->create('application_status_history', [
            'application_id' => $appId,
            'old_status'     => $old,
            'new_status'     => $target,
            'changed_by'     => $actorId,
            'note'           => $histNote,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        // Audit
        self::audit($crud, $actorId, 'application', $appId, 'application.status_reset', [
            'from'   => $old,
            'to'     => $target,
            'reason' => ($reason !== '' ? $reason : null),
        ]);

        return ['success'=>true,'message'=>'OK','code'=>200,'data'=>['new_status'=>$target]];
    }

    private static function build_cv_snapshot(Crud $crud, int $userId): array
    {
        // 1) user_cv satırını güvenli şekilde çek: `references` -> alias (refs)
        $rows = $crud->query("
            SELECT 
                `phone`,
                `social`,
                `email`,
                `language`,
                `education`,
                `work_experience`,
                `skills`,
                `certificates`,
                `seafarer_info`,
                `references` AS `refs`
            FROM `user_cv`
            WHERE `user_id` = :uid
            LIMIT 1
        ", [':uid' => $userId]);

        $row = (is_array($rows) && isset($rows[0])) ? $rows[0] : null;

        // 2) Değerleri listeye normalleştiren yardımcı
        $toList = function ($v): array {
            if ($v === null) return [];
            if (is_array($v)) return array_values($v);
            if (is_string($v)) {
                $t = trim($v);
                if ($t === '') return [];
                $dec = json_decode($t, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    return array_values($dec);
                }
                // Düz string ise tek elemanlı liste yap
                return [$t];
            }
            // sayı/bool vs. -> stringe çevirip liste yap
            return [strval($v)];
        };

        // 3) Kayıt yoksa tüm alanlar boş liste
        if (!$row) {
            return [
                'phone'           => [],
                'social'          => [],
                'email'           => [],
                'language'        => [],
                'education'       => [],
                'work_experience' => [],
                'skills'          => [],
                'certificates'    => [],
                'seafarer_info'   => [],
                'references'      => [],
            ];
        }

        // 4) Her alanı normalize et
        return [
            'phone'           => $toList($row['phone']           ?? null),
            'social'          => $toList($row['social']          ?? null),
            'email'           => $toList($row['email']           ?? null),
            'language'        => $toList($row['language']        ?? null),
            'education'       => $toList($row['education']       ?? null),
            'work_experience' => $toList($row['work_experience'] ?? null),
            'skills'          => $toList($row['skills']          ?? null),
            'certificates'    => $toList($row['certificates']    ?? null),
            'seafarer_info'   => $toList($row['seafarer_info']   ?? null),
            'references'      => $toList($row['refs']            ?? null), // <-- alias kullan
        ];
    }

    private static function normalize_attachments($raw): array
    {
        // accepts: null | [] | [{name,url,mime,size}] | JSON string
        if ($raw === null) return [];
        if (is_string($raw)) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) $raw = $dec;
        }
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $it) {
            if (!is_array($it)) continue;
            $name = isset($it['name']) ? (string)$it['name'] : null;
            $url  = isset($it['url'])  ? (string)$it['url']  : null;
            if (!$name || !$url) continue;
            $out[] = [
                'name' => $name,
                'url'  => $url,
                'mime' => isset($it['mime']) ? (string)$it['mime'] : null,
                'size' => isset($it['size']) ? (int)$it['size'] : null,
            ];
        }
        return $out;
    }

    /* ===========================
     * POST (İLAN) ACTIONS
     * =========================== */

    public static function post_create(array $p): array
    {
        $auth = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud = new Crud($actorId);

        $companyId = (int)($p['company_id'] ?? 0);
        Gate::check('recruitment.post.create', $companyId);

        $title = trim((string)($p['title'] ?? ''));
        if ($title === '') {
            return ['success'=>false,'message'=>'Title is required','code'=>422];
        }

        $area = self::sanitizeArea($p['area'] ?? null);

        $description = isset($p['description']) ? (string)$p['description'] : null;
        $positionId  = isset($p['position_id']) ? (int)$p['position_id'] : null;
        $location    = isset($p['location']) ? trim((string)$p['location']) : null;

        $employmentType = self::sanitizeEmployment($p['employment_type'] ?? null);

        $ageMin = self::to_int_or_null($p['age_min'] ?? null);
        $ageMax = self::to_int_or_null($p['age_max'] ?? null);
        if ($r = self::validateAgeRange($ageMin, $ageMax)) if (isset($r['success']) && $r['success']===false) return $r;
        $ageMin = $r['ok']['min']; $ageMax = $r['ok']['max'];

        $salaryMin = self::to_decimal_or_null($p['salary_min'] ?? null);
        $salaryMax = self::to_decimal_or_null($p['salary_max'] ?? null);
        if ($r = self::validateSalaryRange($salaryMin, $salaryMax)) if (isset($r['success']) && $r['success']===false) return $r;
        $salaryMin = $r['ok']['min']; $salaryMax = $r['ok']['max'];

        $salaryCurrency = self::sanitize_currency($p['salary_currency'] ?? null);
        $salaryRateUnit = self::sanitizeSalaryUnit($p['salary_rate_unit'] ?? null);

        $contractMonths  = self::to_int_or_null($p['contract_duration_months'] ?? null);
        $probationMonths = self::to_int_or_null($p['probation_months'] ?? null);

        $rotOn  = self::to_int_or_null($p['rotation_on_months']  ?? null);
        $rotOff = self::to_int_or_null($p['rotation_off_months'] ?? null);

        $bonusType  = self::sanitize_enum($p['rotation_bonus_type'] ?? null, ['none','fixed','one_salary','percent']) ?? 'none';
        $bonusValue = self::to_decimal_or_null($p['rotation_bonus_value'] ?? null);
        if ($bonusType === 'one_salary') $bonusValue = null;
        if ($r = self::validateBonus($bonusType, $bonusValue)) if (isset($r['success']) && $r['success']===false) return $r;

        $cityId = self::to_int_or_null($p['city_id'] ?? null);

        [$benefitsJson,$obligationsJson,$requirementsJson] = (function($o){
            return [$o['benefits_json']??null,$o['obligations_json']??null,$o['requirements_json']??null];
        })( self::normalizeJsonFields($p, ['benefits_json','obligations_json','requirements_json']) );

        $data = [
            'company_id' => $companyId,
            'position_id'=> $positionId,
            'title'      => $title,
            'description'=> $description,
            'area'       => $area,
            'location'   => $location,
            'employment_type' => $employmentType,
            'age_min'    => $ageMin,
            'age_max'    => $ageMax,
            'salary_min' => $salaryMin,
            'salary_max' => $salaryMax,
            'salary_currency'  => $salaryCurrency,
            'salary_rate_unit' => $salaryRateUnit,
            'contract_duration_months' => $contractMonths,
            'probation_months' => $probationMonths,
            'rotation_on_months'  => $rotOn,
            'rotation_off_months' => $rotOff,
            'rotation_bonus_type'  => $bonusType,
            'rotation_bonus_value' => $bonusValue,
            'city_id' => $cityId,
            'benefits_json'    => $benefitsJson,
            'obligations_json' => $obligationsJson,
            'requirements_json'=> $requirementsJson,
            'status'     => 'draft',
            'visibility' => 'public',
            'created_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = $crud->create('job_posts', $data);
        if (!$id) {
            return ['success'=>false,'message'=>'Create failed','code'=>500];
        }

        // Audit
        self::audit($crud, $actorId, 'job_post', (int)$id, 'create', ['fields'=>$data]);

        return [
            'success'=>true,
            'message'=>'Created',
            'data'=>['id'=>(int)$id],
            'code'=>200
        ];
    }

    public static function post_update(array $p): array
    {
        $auth = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud = new Crud($actorId);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) {
            return ['success'=>false,'message'=>'Invalid id','code'=>422];
        }

        // Kayıt çek → company_id öğren
        $row = $crud->read('job_posts', ['id'=>$id], ['id','company_id','status','title'], false);
        if (!$row) {
            return ['success'=>false,'message'=>'Not found','code'=>404];
        }
        $companyId = (int)$row['company_id'];
        $currentStatus = (string)$row['status'];
        Gate::check('recruitment.post.update', $companyId);

        $data = [];
        $auditChanges = [];

        $mapString = [
            'title','description','area','location','employment_type',
            'salary_currency','salary_rate_unit','rotation_bonus_type'
        ];
        foreach ($mapString as $k) {
            if (array_key_exists($k, $p)) {
                $v = trim((string)$p[$k]);
                if ($k === 'area') {
                    $allowedAreas = ['crew','office','port','shipyard','supplier','agency'];
                    if (!in_array($v, $allowedAreas, true)) $v = 'crew';
                }
                if ($k === 'employment_type') {
                    $v = self::sanitize_enum($v, [
                        'full_time','part_time','contract','seasonal','internship','temporary','other'
                    ]);
                }
                if ($k === 'salary_rate_unit') {
                    $v = self::sanitize_enum($v, ['hour','day','month','year','contract','trip']);
                }
                if ($k === 'salary_currency') {
                    $v = self::sanitize_currency($v);
                }
                if ($k === 'rotation_bonus_type') {
                    $v = self::sanitize_enum($v, ['none','fixed','one_salary','percent']) ?? 'none';
                }
                $data[$k] = ($v === '') ? null : $v;
            }
        }

        $mapInt = [
            'position_id','age_min','age_max','contract_duration_months',
            'probation_months','rotation_on_months','rotation_off_months','city_id'
        ];
        foreach ($mapInt as $k) {
            if (array_key_exists($k, $p)) {
                $data[$k] = self::to_int_or_null($p[$k]);
            }
        }

        $mapDec = ['salary_min','salary_max','rotation_bonus_value'];
        foreach ($mapDec as $k) {
            if (array_key_exists($k, $p)) {
                $data[$k] = self::to_decimal_or_null($p[$k]);
            }
        }

        // Mantık kontrolleri
        if (array_key_exists('age_min', $data) && array_key_exists('age_max', $data)
            && $data['age_min'] !== null && $data['age_max'] !== null
            && $data['age_min'] > $data['age_max']) {
            return ['success'=>false,'message'=>'age_min cannot be greater than age_max','code'=>422];
        }

        if (array_key_exists('salary_min', $data) && array_key_exists('salary_max', $data)
            && $data['salary_min'] !== null && $data['salary_max'] !== null
            && (float)$data['salary_min'] > (float)$data['salary_max']) {
            return ['success'=>false,'message'=>'salary_min cannot be greater than salary_max','code'=>422];
        }

        if (array_key_exists('rotation_bonus_type', $data)) {
            $bt = $data['rotation_bonus_type'];
            $bv = $data['rotation_bonus_value'] ?? null;
            if ($bt === 'fixed' && ($bv === null || $bv <= 0)) {
                return ['success'=>false,'message'=>'rotation_bonus_value must be positive for fixed bonus','code'=>422];
            }
            if ($bt === 'percent' && ($bv === null || $bv < 0 || $bv > 100)) {
                return ['success'=>false,'message'=>'rotation_bonus_value must be between 0 and 100 for percent bonus','code'=>422];
            }
            if ($bt === 'one_salary') $data['rotation_bonus_value'] = null;
        }

        // JSON alanları
        foreach (['benefits_json','obligations_json','requirements_json'] as $jk) {
            if (array_key_exists($jk, $p)) {
                $data[$jk] = self::normalize_json($p[$jk]);
            }
        }

        if (empty($data)) {
            return ['success'=>true,'message'=>'No changes','data'=>['id'=>$id],'code'=>200];
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $ok = $crud->update('job_posts', $data, ['id'=>$id]);
        if (!$ok) {
            return ['success'=>false,'message'=>'Update failed','code'=>500];
        }

        self::audit($crud, $actorId, 'job_post', (int)$id, 'update', ['fields'=>$data]);

        $wantsPublish = !empty($p['publish']);

        if ($wantsPublish) {
            Gate::check('recruitment.post.publish', $companyId);

            if ($currentStatus !== 'draft') {
                return ['success'=>false,'message'=>'Invalid state for publish','code'=>409];
            }

            $titleAfter = array_key_exists('title', $data) ? $data['title'] : ($row['title'] ?? null);
            if (!$titleAfter || trim((string)$titleAfter) === '') {
                return ['success'=>false,'message'=>'title is required for publish','code'=>422];
            }

            $pubok = $crud->update('job_posts', [
                'status'       => 'published',
                'published_at' => self::nowUtc(),
                'updated_at'   => self::nowUtc(),
            ], ['id'=>$id]);

            if (!$pubok) {
                return ['success'=>false,'message'=>'Publish failed','code'=>500];
            }

            self::audit($crud, $actorId, 'job_post', (int)$id, 'publish', []);

            return [
                'success'=>true,
                'message'=>'Updated and published',
                'data'=>['id'=>$id,'status'=>'published'],
                'code'=>200
            ];
        }

        return ['success'=>true,'message'=>'Updated','data'=>['id'=>$id],'code'=>200];
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
            'closed_at'  => self::nowUtc(),   // ✅ eklendi
            'updated_at' => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Close failed', 'code'=>500];

        self::revokeSharesForClosedPost($crud, (int)$id['ok']);

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id['ok'], 'close', []);

        return ['success'=>true, 'message'=>'Post closed', 'data'=>['id'=>(int)$id['ok']], 'code'=>200];
    }

    public static function post_detail(array $p): array
    {
        $auth = Auth::requireAuth(); // şimdilik public değil
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;

        // JOIN: position_catalog → area/department/name/description
        $row = $crud->query("
            SELECT 
                jp.*,
                pc.area        AS position_area,
                pc.department  AS position_department,
                pc.name        AS position_name,
                pc.description AS position_description
            FROM job_posts jp
            LEFT JOIN position_catalog pc ON pc.id = jp.position_id
            WHERE jp.id = :id
            LIMIT 1
        ", [':id' => $id['ok']]);

        if (!$row) {
            return ['success'=>false, 'message'=>'Post not found', 'code'=>404];
        }

        $post = $row[0];

        // Yayınlanmamışsa şirket içi görüntüleme izni iste
        if ((string)$post['status'] !== 'published') {
            Gate::check('recruitment.post.view', (int)$post['company_id']);
        }

        return ['success'=>true, 'message'=>'OK', 'data'=>['post'=>$post], 'code'=>200];
    }

    public static function post_unarchive(array $p): array
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

        // izin
        Gate::check('recruitment.post.unarchive', (int)$post['company_id']);

        // sadece archived → draft
        if ((string)$post['status'] !== 'archived') {
            return ['success'=>false, 'message'=>'Only archived posts can be unarchived', 'code'=>409];
        }

        $ok = $crud->update('job_posts', [
            'status'     => 'draft',
            'updated_at' => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) {
            return ['success'=>false, 'message'=>'Unarchive failed', 'code'=>500];
        }

        self::audit($crud, (int)$auth['user_id'], 'job_post', (int)$id['ok'], 'unarchive', []);

        return [
            'success' => true,
            'message' => 'Post unarchived to draft',
            'data'    => ['id' => (int)$id['ok'], 'status' => 'draft'],
            'code'    => 200
        ];
    }
    public static function post_overview(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $id = self::require_int($p['id'] ?? null, 'id');
        if (isset($id['success'])) return $id;
        $recent = max(0, (int)($p['recent'] ?? 5));

        // JOIN: position_catalog
        $row = $crud->query("
            SELECT 
                jp.*,
                pc.area        AS position_area,
                pc.department  AS position_department,
                pc.name        AS position_name,
                pc.description AS position_description
            FROM job_posts jp
            LEFT JOIN position_catalog pc ON pc.id = jp.position_id
            WHERE jp.id = :id
            LIMIT 1
        ", [':id' => $id['ok']]);

        if (!$row) return ['success'=>false, 'message'=>'Post not found', 'code'=>404];

        $post = $row[0];
        $companyId = (int)$post['company_id'];

        // İç görünüm izni
        Gate::check('recruitment.post.view', $companyId);

        // --- (devamı aynen) istatistikler + recent ---
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

        $q = isset($p['q']) ? trim((string)$p['q'])
            : (isset($p['query']) ? trim((string)$p['query']) : '');

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = min(100, max(1, (int)($p['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        // Sadece bu izne bakıyoruz (iç listeleme için)
        $isInternalViewer = ($companyId && $companyId > 0)
            ? Gate::allows('recruitment.post.view', $companyId)
            : false;

        $allowedStatuses = ['draft','published','closed','archived'];
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            $status = null;
        }

        $params = [];
        $w = ['1=1'];

        if ($companyId && $companyId > 0) {
            $w[] = 'company_id = :cid';
            $params[':cid'] = $companyId;
        }

        if ($isInternalViewer) {
            if ($status !== null) {
                $w[] = 'status = :st';
                $params[':st'] = $status;
            }
        } else {
            $w[] = 'status = :st';
            $params[':st'] = 'published';
        }


        if ($q !== '') {
            $w[] = '(title LIKE :q OR description LIKE :q)';
            $params[':q'] = '%'.$q.'%';
        }

        $whereSql = implode(' AND ', $w);

        $limit = (int)$perPage;
        $off   = (int)$offset;

        $sql = "SELECT * FROM job_posts WHERE $whereSql ORDER BY id DESC LIMIT :lim OFFSET :off";

        // ---- teşhis logları
        Logger::info("Recruitment.post_list mode=" . ($isInternalViewer ? 'internal' : 'public')
            . " company_id=" . ($companyId ?? 'NULL')
            . " status=" . ($status ?? 'NULL')
            . " q='$q'");

        Logger::info("SQL: $sql ; PARAMS: " . json_encode($params, JSON_UNESCAPED_UNICODE));

        $rows = $crud->query($sql, array_merge($params, [':lim'=>$limit, ':off'=>$off])) ?: [];
        $countSql = "SELECT COUNT(*) AS t FROM job_posts WHERE $whereSql";
        $totalRow = $crud->query($countSql, $params);
        $total = (int)($totalRow[0]['t'] ?? 0);

        Logger::info("RESULT: items=" . count($rows) . " total=$total");

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => [
                'items'     => $rows,
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
            ],
            'code'    => 200,
        ];
    }

    public static function post_public_detail(array $p): array
    {
        // ❌ $auth = Auth::requireAuth();
        // ❌ $crud = new Crud((int)$auth['user_id']);

        // ✅ public read-only
        $crud = new Crud(0, false);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) {
            return ['success'=>false,'message'=>'Invalid id','code'=>422];
        }

        $sql = "
            SELECT
                jp.id, jp.company_id, jp.position_id,
                jp.title, jp.description, jp.area, jp.location,
                jp.employment_type,
                jp.age_min, jp.age_max,
                jp.salary_min, jp.salary_max, jp.salary_currency, jp.salary_rate_unit,
                jp.contract_duration_months, jp.probation_months,
                jp.rotation_on_months, jp.rotation_off_months,
                jp.rotation_bonus_type, jp.rotation_bonus_value,
                jp.city_id,
                jp.benefits_json, jp.obligations_json, jp.requirements_json,
                jp.status, jp.visibility,
                jp.created_at, jp.published_at, jp.updated_at,
                c.name  AS company_name,
                c.logo  AS company_logo,
                c2.city AS city_name,
                c2.iso2 AS city_iso2,
                c2.iso3 AS city_iso3
            FROM job_posts jp
            LEFT JOIN companies c ON c.id = jp.company_id
            LEFT JOIN cities    c2 ON c2.id = jp.city_id
            WHERE jp.id = :id
            AND jp.status = 'published'
            AND jp.visibility = 'public'
            AND jp.deleted_at IS NULL
            LIMIT 1
        ";

        $rows = $crud->query($sql, [':id' => $id]);
        if (!$rows || count($rows) === 0) {
            return ['success'=>false,'message'=>'Not found','code'=>404];
        }

        return ['success'=>true,'message'=>'OK','data'=>$rows[0],'code'=>200];
    }
    public static function post_public_open_list(array $p): array
    {
        // ⬇️ public: auth zorunlu değil
        // $auth = Auth::requireAuth();
        // $crud = new Crud((int)$auth['user_id']);
        $crud = new Crud(0, false); // sadece read yapıyoruz

        $limit = (int)($p['limit'] ?? 10);
        $limit = max(1, min(50, $limit));

        $q = isset($p['q']) ? trim((string)$p['q'])
            : (isset($p['query']) ? trim((string)$p['query']) : '');

        $params = [];
        $w = ["jp.status = 'published'", "jp.visibility = 'public'", 'jp.deleted_at IS NULL'];

        if ($q !== '') {
            $w[] = '(jp.title LIKE :q OR jp.description LIKE :q)';
            $params[':q'] = '%'.$q.'%';
        }

        $whereSql = implode(' AND ', $w);

        $sql = "
            SELECT
                jp.id, jp.title, jp.updated_at, jp.published_at, jp.created_at,
                jp.location, jp.city_id,
                jp.salary_min, jp.salary_max, jp.salary_currency, jp.salary_rate_unit,
                jp.rotation_on_months, jp.rotation_off_months,
                c.name  AS company_name,
                c.logo  AS company_logo,
                c2.city AS city_name,
                jp.applications_active_count,
                jp.applications_total_count,
                c2.iso2 AS city_iso2
            FROM job_posts jp
            LEFT JOIN companies c ON c.id = jp.company_id
            LEFT JOIN cities    c2 ON c2.id = jp.city_id
            WHERE $whereSql
            ORDER BY COALESCE(jp.updated_at, jp.published_at, jp.created_at) DESC
            LIMIT $limit
        ";

        // 🔎 Teşhis logları
        Logger::info("OpenList q='$q' limit=$limit");
        Logger::info("SQL: $sql ; PARAMS: " . json_encode($params, JSON_UNESCAPED_UNICODE));

        $rows = $crud->query($sql, $params);
        Logger::info("RESULT: items=" . count($rows));

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>['items'=>$rows,'total'=>count($rows)],
            'code'=>200
        ];
    }

    public static function app_create(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $cid = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($cid['success'])) return $cid;

        $pid = self::require_int($p['job_post_id'] ?? null, 'job_post_id');
        if (isset($pid['success'])) return $pid;

        $uid = isset($p['user_id']) ? (int)$p['user_id'] : (int)$auth['user_id'];

        Gate::check('recruitment.app.create', (int)$cid['ok']);

        $now = self::nowUtc();
        $id = $crud->create('applications', [
            'company_id'      => (int)$cid['ok'],
            'job_post_id'     => (int)$pid['ok'],
            'user_id'         => $uid,
            'status'          => 'submitted',
            'created_at'      => $now,
            'updated_at'      => $now,
            // 'cover_letter' => isset($p['cover_letter']) ? (string)$p['cover_letter'] : null,
            // 'cv_snapshot'  => isset($p['cv_snapshot']) ? json_encode($p['cv_snapshot']) : null,
            // 'attachments'  => isset($p['attachments']) ? json_encode($p['attachments']) : null,
        ]);

        if (!$id) return ['success'=>false,'message'=>'Create failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$id, 'create', [
            'job_post_id' => (int)$pid['ok'],
            'user_id'     => $uid,
        ]);

        // 🔢 Counters: total++ ve active++
        $crud->query("
            UPDATE job_posts
            SET applications_total_count  = applications_total_count  + 1,
                applications_active_count = applications_active_count + 1
            WHERE id = :pid AND company_id = :cid
        ", [ ':pid' => (int)$pid['ok'], ':cid' => (int)$cid['ok'] ]);
        
        try {
            $actorId          = (int)$auth['user_id'];
            $applicationId    = (int)$id;
            $from             = null;
            $newStatus        = 'submitted';
            $actorIsApplicant = ($actorId === (int)$uid); // şirket adına oluşturulduysa false olur

            $targets = self::idsOfCompanyReviewers($crud, (int)$cid['ok'], $actorId); // başvuran/aktör hariç
            if (!empty($targets)) {
                NotificationsHandler::emit(
                    $crud,
                    'app_status',
                    $actorId,
                    $applicationId,
                    $targets,
                    [
                        'application_id'     => $applicationId,
                        'old'                => $from,
                        'new'                => $newStatus,
                        'actor_id'           => $actorId,
                        'actor_is_applicant' => $actorIsApplicant,
                        'target_count'       => count($targets),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Logger::error('notif_submit_emit_fail', ['e'=>$e->getMessage(), 'app_id'=>(int)$id]);
        }

        return ['success'=>true,'message'=>'Created','data'=>['id'=>(int)$id],'code'=>200];
    }

    /* ===========================
     * APPLICATION (BAŞVURU) ACTIONS
     * =========================== */
    public static function app_submit(array $p): array
    {
        // 1) Auth & context
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        // 2) company_id doğrula
        $cidRes = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($cidRes['success'])) return $cidRes; // require_int fail formatınız buysa koruyalım
        $companyId = (int)$cidRes['ok'];

        // 3) global kontroller
        Gate::checkVerified();
        Gate::checkBlocked();
        // 4) üye şirket çalışanı kendi şirketine başvuramasın
        try {
            $memberRow = $crud->read(
                'company_users',
                ['company_id' => $companyId, 'user_id' => (int)$auth['user_id'], 'status' => 'active'],
                ['id'],
                false
            );
            if ($memberRow) {
                Logger::error("Company member submitted application to their own company.", ['company_id' => $companyId, 'user_id' => (int)$auth['user_id']]);
                Response::error("Company members cannot submit applications to their own company.", 403);
            }
        } catch (\Exception $e) {
            // loglamak istersen:
            Logger::error("company_users lookup failed: ".$e->getMessage());
        }

        // 5) paramlar
        $jobPostId = isset($p['job_post_id']) ? (int)$p['job_post_id'] : null;
        $cover     = trim((string)($p['cover_letter'] ?? ''));

        // 6) job_post doğrulaması (public + published + doğru şirkete ait)
        if ($jobPostId) {
            $post = $crud->read('job_posts', ['id' => $jobPostId], false);
            if (!$post) {
                Response::error("Job post not found.", 404);
            }
            if ((int)($post['company_id'] ?? 0) !== $companyId) {
                Response::error("Job post does not belong to this company.", 403);
            }
            $visibility = ($post['visibility'] ?? 'public');
            $status     = ($post['status'] ?? '');
            if ($visibility !== 'public' || $status !== 'published') {
                Response::error("This job post is not open for public applications.", 403);
            }
        }

        // 7) duplicate aktif başvuru guard (409)
        if ($jobPostId) {
            // NOT: active_job_key bir tablo değil; applications tablosunu sorgula
            $grd = $crud->read(
                'applications',
                ['user_id'    => (int)$auth['user_id'], 'job_post_id'=> $jobPostId, 'status' => ['IN', ['submitted','under_review','shortlisted','interview','offered']],],
                ['id'], false, [], [], ['limit' => 1]
            );

            if ($grd) {
                Logger::info('Guard hit: duplicate active application.', [
                    'user_id' => (int)$auth['user_id'],
                    'job_post_id' => $jobPostId
                ]);
                Response::error('Active application already exists.', 409); // exit
            } else {
                Logger::info('Guard OK: no active app.', [
                    'user_id' => (int)$auth['user_id'],
                    'job_post_id' => $jobPostId
                ]);
            }
        }

        // 8) attachments normalize → JSON
        $attachmentsArr = self::normalize_attachments($p['attachments'] ?? null);
        $attachmentsJson = $attachmentsArr ? json_encode($attachmentsArr, JSON_UNESCAPED_UNICODE) : null;

        // 9) CV snapshot (opsiyonel / include flag ile DB'den üret)
        $cvPayload = $p['cv_snapshot'] ?? null;
        $includeFlag = (int)($p['include_cv_snapshot'] ?? 0);
        if ($includeFlag === 1 && $cvPayload === null) {
            $cvPayload = self::build_cv_snapshot($crud, (int)$auth['user_id']); // user_cv + users’tan derlenmiş tek obje
        }
        $cvJson = ($cvPayload !== null) ? json_encode($cvPayload, JSON_UNESCAPED_UNICODE) : null;

        // 10) insert (duplicate race’ine karşı try/catch)
        $now = self::nowUtc();
        $ins = [
            'user_id'      => (int)$auth['user_id'],
            'company_id'   => $companyId,
            'job_post_id'  => $jobPostId ?: null,
            'cover_letter' => ($cover !== '') ? $cover : null,
            'cv_snapshot'  => $cvJson,          // NULL olabilir
            'attachments'  => $attachmentsJson, // NULL olabilir
            'status'       => 'submitted',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        try {
            $newId = $crud->create('applications', $ins);
        } catch (\PDOException $e) {
            // MySQL/MariaDB duplicate key → 23000
            $sqlState = $e->getCode(); // "23000"
            $msg = $e->getMessage() ?? '';
            // uniq key adını kontrol et (schema’daki isim: uq_app_single_active)
            if ($sqlState === '23000' && stripos($msg, 'uq_app_single_active') !== false) {
                Response::error("Active application already exists.", 409);
            }
            // başka bir constraint/DB hatası
            Response::error("Application submission failed.", 500);
        }
        if (!$newId) {
            Response::error("Application submission failed.", 500);
        }

        // 11) audit (opsiyonel)
        self::audit(
            $crud,
            (int)$auth['user_id'],
            'application',
            (int)$newId,
            'submit',
            [
                'has_cv_snapshot'         => ($cvJson !== null),
                'attachments_count'       => is_array($attachmentsArr) ? count($attachmentsArr) : 0,
                'include_cv_snapshot_flag'=> $includeFlag,
                'job_post_id'             => $jobPostId,
                'company_id'              => $companyId,
            ]
        );

        // 12) success (exit)
        Response::success(null, 'Application submitted');

        // Unreachable (Response::ok exit ediyor) — imza gereği boş bir dönüş bırakıyoruz
        return [];
    }

    public static function app_list_for_company(array $p): array
    {
        $auth = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud = new Crud($actorId);

        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId <= 0) {
            return ['success'=>false,'message'=>'Invalid company_id','code'=>422];
        }

        // izin
        Gate::check('recruitment.app.view_company', $companyId);

        // sayfalama
        $perPage = (int)($p['per_page'] ?? 25);
        $perPage = max(1, min(100, $perPage));
        $page = (int)($p['page'] ?? 1);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        // filtreler
        $status = isset($p['status']) ? trim((string)$p['status']) : null;
        $q      = isset($p['q']) ? trim((string)$p['q']) : null;

        $where = ["a.company_id = :cid"];
        $params = [':cid' => $companyId];

        if ($status && in_array($status, [
            'submitted','under_review','shortlisted','interview','offered','hired','rejected','withdrawn'
        ], true)) {
            $where[] = "a.status = :st";
            $params[':st'] = $status;
        }

        // q filtresi (opsiyonel)
        if ($q !== null && $q !== '') {
            $qLike = '%'.$q.'%';
            $where[] = "("
                // aday adı-soyadı
                ."TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,''))) LIKE :q "
                // reviewer adı-soyadı
                ."OR TRIM(CONCAT(COALESCE(r.name,''),' ',COALESCE(r.surname,''))) LIKE :q "
                // ilan başlığı
                ."OR COALESCE(jp.title,'') LIKE :q "
                // status LIKE (örn. ‘under’ yazınca ‘under_review’ eşleşsin)
                ."OR a.status LIKE :q "
                // sayısal aramalar (id / user_id / job_post_id)
                .(ctype_digit($q) ? "OR a.id = :qid OR a.user_id = :qid OR a.job_post_id = :qid " : "")
            .")";
            $params[':q'] = $qLike;
            if (ctype_digit($q)) {
                $params[':qid'] = (int)$q;
            }
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT SQL_CALC_FOUND_ROWS
                a.id, a.user_id, a.company_id, a.job_post_id, a.reviewer_user_id,
                a.status, a.created_at, a.updated_at,

                -- Başvuran
                TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,'')))            AS user_full_name,
                NULLIF(TRIM(u.user_image), '')                                          AS user_image_name,

                -- Reviewer
                NULLIF(TRIM(CONCAT(COALESCE(r.name,''),' ',COALESCE(r.surname,''))), '') AS reviewer_full_name,

                -- İlan
                jp.title AS job_title

            FROM applications a
            LEFT JOIN users u  ON u.id = a.user_id
            LEFT JOIN users r  ON r.id = a.reviewer_user_id
            LEFT JOIN job_posts jp ON jp.id = a.job_post_id
            WHERE $whereSql
            ORDER BY a.id DESC
            LIMIT $perPage OFFSET $offset
        ";

        $rows = $crud->query($sql, $params) ?: [];

        $tot = 0;
        $r2 = $crud->query('SELECT FOUND_ROWS() AS t', []);
        if ($r2 && isset($r2[0]['t'])) $tot = (int)$r2[0]['t'];

        // logger::info('Row data = '.json_encode($rows, JSON_UNESCAPED_UNICODE));

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>[
                'items'=>$rows,
                'total'=>$tot,
                'page'=>$page,
                'per_page'=>$perPage,
                'pages'=>$tot>0 ? (int)ceil($tot/$perPage) : 0,
            ],
            'code'=>200,
        ];
    }

    public static function app_list_for_user(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $requestedUserId = isset($p['user_id']) ? (int)$p['user_id'] : (int)$auth['user_id'];
        $isSelf = ($requestedUserId === (int)$auth['user_id']) && !isset($p['company_id']);

        if ($isSelf) {
            // sayfalama/filtre/FTS destekli yeni uç
            return self::list_mine($p);
        }

        // ↓↓↓ mevcut “başkasını şirket izniyle listele” mantığını olduğu gibi koru
        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;
        Gate::check('recruitment.app.view_company', $companyId['ok']);

        $rows = $crud->read(
            'applications',
            ['user_id' => $requestedUserId],
            ['id','company_id','job_post_id','reviewer_user_id','status','created_at','updated_at']
        );

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
            return ['success'=>false, 'message'=>'Invalid status (allowed: '.implode(',', self::allow_app_statuses()).')', 'code'=>422];
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

        $wasActive = self::is_active_app_status((string)$from);
        $isActive  = self::is_active_app_status((string)$newStatus);

        if ($wasActive && !$isActive) {
            // active--
            $crud->query("
                UPDATE job_posts
                SET applications_active_count = GREATEST(applications_active_count - 1, 0)
                WHERE id = :pid AND company_id = :cid
            ", [ ':pid' => (int)$app['job_post_id'], ':cid' => (int)$app['company_id'] ]);
        } elseif (!$wasActive && $isActive) {
            // active++
            $crud->query("
                UPDATE job_posts
                SET applications_active_count = applications_active_count + 1
                WHERE id = :pid AND company_id = :cid
            ", [ ':pid' => (int)$app['job_post_id'], ':cid' => (int)$app['company_id'] ]);
        }

        // --- Bildirim mantığı (karşı tarafa gönder) ---
        try {
            $actorId = (int)$auth['user_id'];
            $appUserId = (int)$app['user_id'];
            $companyId = (int)$app['company_id'];
            $applicationId = (int)$appId['ok'];

            // Aktörü saptayalım: başvuru sahibi mi şirket tarafı mı?
            $actorIsApplicant = ($actorId === $appUserId);

            if ($actorIsApplicant) {
                // Aday kendi başvurusunu etkiledi (ör: withdraw) → Şirket reviewer’larına bildir
                $targets = self::idsOfCompanyReviewers($crud, $companyId, $actorId);
                if (!empty($targets)) {
                    NotificationsHandler::emit(
                        $crud,
                        'app_status',
                        $actorId,
                        $applicationId,
                        $targets,
                        [
                            'application_id'=>$applicationId,
                            'old'=>$from, 'new'=>$newStatus,
                            'actor_id'=>$actorId,
                            'actor_is_applicant' => $actorIsApplicant,
                            'target_count'=> count($targets)
                        ]
                    );
                }
            } else {
                // Şirket tarafı statüyü değiştirdi → Adaya bildir
                $targets = [$appUserId];
                // Özellikle kritik geçişlerde (offered/hired/rejected) garanti veriyoruz; diğerleri de akabilir.
                $critical = in_array($newStatus, ['offered','hired','rejected'], true);
                if ($critical || true) {
                    NotificationsHandler::emit(
                        $crud,
                        'app_status',
                        $actorId,
                        $applicationId,
                        $targets,
                        [
                            'application_id'=>$applicationId,
                            'old'=>$from, 'new'=>$newStatus,
                            'actor_id'=>$actorId,
                            'actor_is_applicant' => $actorIsApplicant,
                            'target_count'=> count($targets)
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            Logger::error('notif_status_emit_fail', [
                'e'=>$e->getMessage(),
                'app_id'=>$appId['ok'] ?? null,
                'old'=>$from ?? null,
                'new'=>$newStatus ?? null
            ]);
        }

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

        $app = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id'], true);
        if (!$app) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];

        Gate::check('recruitment.app.review', (int)$app[0]['company_id']);

        $raw = $p['note'] ?? null;
        if (!is_string($raw)) return ['success'=>false, 'message'=>'Note is required', 'code'=>422];
        $text = trim(strip_tags($raw));
        if ($text === '') return ['success'=>false, 'message'=>'Note cannot be empty', 'code'=>422];
        if (mb_strlen($text) > 1000) return ['success'=>false, 'message'=>'Note is too long (max 1000)', 'code'=>422];
        $vis = strtolower(trim((string)($p['visibility'] ?? 'company')));
        if (!in_array($vis, ['company','applicant'], true)) $vis = 'company';

        $ins = [
            'application_id' => (int)$appId['ok'],
            'author_user_id' => (int)$auth['user_id'],
            'note'           => $text,
            'created_at'     => self::nowUtc(),
            'visibility'     => $vis,
            'visible_at'     => ($vis === 'applicant') ? self::nowUtc() : null,
        ];
        $newId = $crud->create('application_notes', $ins);
        if (!$newId) return ['success'=>false, 'message'=>'Note insert failed', 'code'=>500];

        // (Opsiyonel) audit log
        self::audit($crud, (int)$auth['user_id'], 'application_note', (int)$newId, 'add_note', [
            'application_id'=>(int)$appId['ok']
        ]);

        return ['success'=>true, 'message'=>'Note added', 'data'=>['id'=>(int)$newId], 'code'=>200];
    }

    public static function app_notes(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $app = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id','user_id'], false);
        if (!$app) return ['success'=>false,'message'=>'Application not found','code'=>404];

        $isOwner = ((int)$app['user_id'] === (int)$auth['user_id']);
        if (!$isOwner) Gate::check('recruitment.app.review', (int)$app['company_id']);

        $scope = strtolower(trim((string)($p['scope'] ?? ($isOwner ? 'applicant' : 'company'))));

        $sql = $isOwner
            ? // Aday: sadece yayımlanmış applicant notları
            "SELECT n.id,n.note,n.created_at,n.author_user_id,n.visibility,n.visible_at,
                    TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,''))) AS author_full_name,
                    NULLIF(TRIM(u.user_image),'') AS author_image
            FROM application_notes n
            LEFT JOIN users u ON u.id=n.author_user_id
            WHERE n.application_id=:aid AND n.deleted_at IS NULL
                AND n.visibility='applicant' AND n.visible_at IS NOT NULL
            ORDER BY n.id DESC"
            : // Şirket: silinmemiş tüm notlar
            "SELECT n.id,n.note,n.created_at,n.author_user_id,n.visibility,n.visible_at,
                    TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,''))) AS author_full_name,
                    NULLIF(TRIM(u.user_image),'') AS author_image
            FROM application_notes n
            LEFT JOIN users u ON u.id=n.author_user_id
            WHERE n.application_id=:aid AND n.deleted_at IS NULL
            ORDER BY n.id DESC";

        $rows = $crud->query($sql, [':aid'=>(int)$appId['ok']]) ?: [];

        $items = array_map(function($r){
            $fullName = $r['author_full_name'] ?: ('User #'.(int)$r['author_user_id']);
            $img = $r['author_image'] ? ('uploads/user/user/'.$r['author_image']) : null;
            return [
                'id'                   => (int)$r['id'],
                'note'                 => $r['note'],
                'text'                 => $r['note'],
                'created_at'           => $r['created_at'],
                'created_by_user_id'   => (int)$r['author_user_id'],
                'created_by_full_name' => $fullName,
                'created_by_avatar'    => $img,
                'visibility'           => $r['visibility'] ?? 'company',
                'visible_at'           => $r['visible_at'] ?? null,
            ];
        }, $rows);

        return ['success'=>true,'message'=>'OK','data'=>['items'=>$items],'code'=>200];
    }

    public static function app_note_publish(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $noteId = (int)($p['note_id'] ?? 0);
        if ($noteId <= 0) return ['success'=>false,'message'=>'note_id required','code'=>422];

        $note = $crud->read('application_notes', ['id'=>$noteId], ['id','application_id','visibility','visible_at','deleted_at'], false);
        if (!$note) return ['success'=>false,'message'=>'Note not found','code'=>404];
        if (!empty($note['deleted_at']) || !empty($note['visible_at']) || ($note['visibility'] ?? 'company') !== 'company') {
            return ['success'=>false,'message'=>'Note already visible or deleted','code'=>403];
        }

        // Uygulama şirket izni kontrolü
        $app = $crud->read('applications', ['id'=>$note['application_id']], ['company_id'], false);
        if (!$app) return ['success'=>false,'message'=>'Application not found','code'=>404];
        Gate::check('recruitment.app.review', (int)$app['company_id']);

        $ok = $crud->update('application_notes', [
            'visibility' => 'applicant',
            'visible_at' => self::nowUtc(),
        ], ['id'=>$noteId]);

        if (!$ok) return ['success'=>false,'message'=>'Publish failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application_note', (int)$noteId, 'publish', ['application_id'=>(int)$note['application_id']]);
        // // NOTIF: note_published

        return ['success'=>true,'message'=>'Published','data'=>['id'=>$noteId],'code'=>200];
    }

    public static function app_note_delete(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $noteId = (int)($p['note_id'] ?? 0);
        if ($noteId <= 0) return ['success'=>false,'message'=>'note_id required','code'=>422];

        $note = $crud->read('application_notes', ['id'=>$noteId], ['id','application_id','visible_at','deleted_at'], false);
        if (!$note) return ['success'=>false,'message'=>'Note not found','code'=>404];
        if (!empty($note['deleted_at']) || !empty($note['visible_at'])) {
            return ['success'=>false,'message'=>'Note not deletable','code'=>403];
        }

        // Uygulama şirket izni kontrolü (veya yazar yetkisi)
        $app = $crud->read('applications', ['id'=>$note['application_id']], ['company_id'], false);
        if (!$app) return ['success'=>false,'message'=>'Application not found','code'=>404];
        Gate::check('recruitment.app.review', (int)$app['company_id']);

        $ok = $crud->update('application_notes', ['deleted_at'=>self::nowUtc()], ['id'=>$noteId]);
        if (!$ok) return ['success'=>false,'message'=>'Delete failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application_note', (int)$noteId, 'delete', ['application_id'=>(int)$note['application_id']]);

        return ['success'=>true,'message'=>'Deleted','data'=>['id'=>$noteId],'code'=>200];
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

    public static function app_assign_reviewer(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $rows = $crud->read('applications', ['id'=>$appId['ok']], ['*'], true);
        if (!$rows) return ['success'=>false,'message'=>'Application not found','code'=>404];
        $app = $rows[0];

        Gate::check('recruitment.app.assign', (int)$app['company_id']);

        // reviewer_user_id null olabilir → clear
        $revRaw = $p['reviewer_user_id'] ?? null;
        $revId  = ($revRaw === null || $revRaw === '') ? null : (int)$revRaw;

        if ($revId !== null && $revId <= 0) {
            return ['success'=>false, 'message'=>'Invalid reviewer_user_id','code'=>422];
        }

        if ($revId !== null) {
            // (opsiyonel) aday gerçekten bu şirkette ofis pozisyonunda mı doğrula
            $chk = $crud->query("
                SELECT 1
                FROM company_users cu
                JOIN company_positions cp ON cp.id = cu.position_id
                WHERE cu.company_id = :cid
                AND cu.user_id    = :uid
                AND cu.status     = 'approved'
                AND cu.is_active  = 1
                AND cp.area       = 'office'
                LIMIT 1
            ", [':cid'=>(int)$app['company_id'], ':uid'=>$revId]);
            if (!$chk) return ['success'=>false,'message'=>'User not eligible as reviewer','code'=>422];
        }

        $ok = $crud->update('applications', [
            'reviewer_user_id' => $revId, // NULL → clear
            'updated_at'       => self::nowUtc(),
        ], ['id'=>$appId['ok']]);

        if (!$ok) return ['success'=>false,'message'=>'Assignment failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$appId['ok'], 'assign_reviewer', [
            'reviewer_user_id' => $revId,
        ]);

        return ['success'=>true,'message'=>'Reviewer '.($revId===null?'cleared':'assigned'),
                'data'=>['id'=>(int)$appId['ok'],'reviewer_user_id'=>$revId],'code'=>200];
    }

    public static function app_timeline(array $p): array
    {
        $auth  = Auth::requireAuth();
        $crud  = new Crud((int)$auth['user_id']);

        $appId = (int)($p['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['success'=>false, 'message'=>'application_id is required', 'code'=>422];
        }

        // Uygulama satırı ve yetki kontrolü
        $app = $crud->read('applications', ['id'=>$appId], ['id','company_id','user_id','status'], true);
        if (!$app) return ['success'=>false, 'message'=>'Application not found', 'code'=>404];

        $row = is_array($app[0] ?? null) ? $app[0] : $app;
        $isOwner = ((int)$row['user_id'] === (int)$auth['user_id']);
        if (!$isOwner) {
            // Şirket tarafı görüntülüyorsa izin kontrolü
            Gate::check('recruitment.app.review', (int)$row['company_id']);
        }
        $isCompanyViewer = !$isOwner;

        // --- Status geçişleri (old_status/new_status/changed_by/note/created_at)
        $statusRows = $crud->query("
            SELECT id, old_status, new_status, changed_by AS actor_id, note, created_at
            FROM application_status_history
            WHERE application_id = :appId
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ", [':appId'=>$appId]) ?: [];

        // --- Notlar
        // Şema yeni alanları destekliyorsa (visibility, visible_at, deleted_at) onlarla çek; yoksa sade sürüme düş.
        $noteRows = [];
        try {
            if ($isCompanyViewer) {
                // Şirket: silinmemiş tüm notlar (company + applicant), publish zorunlu değil
                $noteRows = $crud->query("
                    SELECT
                    n.id,
                    n.note,
                    n.author_user_id AS actor_id,
                    n.created_at,
                    n.visibility,
                    n.visible_at,
                    n.deleted_at
                    FROM application_notes n
                    WHERE n.application_id = :appId
                    AND n.deleted_at IS NULL
                    ORDER BY n.created_at DESC, n.id DESC
                    LIMIT 50
                ", [':appId'=>$appId]) ?: [];
            } else {
                // Aday: yalnız published applicant notları
                $noteRows = $crud->query("
                    SELECT
                    n.id,
                    n.note,
                    n.author_user_id AS actor_id,
                    n.created_at,
                    n.visibility,
                    n.visible_at,
                    n.deleted_at
                    FROM application_notes n
                    WHERE n.application_id = :appId
                    AND n.deleted_at IS NULL
                    AND n.visibility = 'applicant'
                    AND n.visible_at IS NOT NULL
                    ORDER BY n.created_at DESC, n.id DESC
                    LIMIT 50
                ", [':appId'=>$appId]) ?: [];
            }
        } catch (\Throwable $e) {
            // Eski şema fallback (visibility/visible_at/deleted_at yoksa)
            $noteRows = $crud->query("
                SELECT id, note, author_user_id AS actor_id, created_at
                FROM application_notes
                WHERE application_id = :appId
                ORDER BY created_at DESC, id DESC
                LIMIT 50
            ", [':appId'=>$appId]) ?: [];
            // Bu durumda visibility/published bilgisi olmadan ilerleyeceğiz (varsayılan company & published=false sayılır).
        }

        // --- Reviewer atama/kaldırma (audit_events)
        $auditRows = $crud->query("
            SELECT id, actor_id, action, meta, created_at
            FROM audit_events
            WHERE entity_type = 'application'
            AND entity_id   = :appId
            AND action IN ('application.assign_reviewer','application.unassign_reviewer','assign_reviewer','unassign_reviewer')
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ", [':appId'=>$appId]) ?: [];

        // --- Doc Requests / Items (opsiyonel: tablo yoksa sessiz atla)
        $docRows = [];
        try {
            $docRows = $crud->query("
            SELECT
                'request' AS kind,
                r.id      AS id,
                r.created_at AS ts,
                r.created_by AS actor_id,
                r.status  AS req_status,   -- open|closed
                r.due_at  AS due_at,
                NULL      AS item_status,
                NULL      AS review_note
            FROM document_requests r
            WHERE r.application_id = :appId

            UNION ALL

            SELECT
                'item' AS kind,
                i.id   AS id,
                COALESCE(i.reviewed_at, i.created_at) AS ts,
                i.reviewed_by AS actor_id,
                NULL   AS req_status,
                NULL   AS due_at,
                i.status AS item_status,   -- approved|rejected|pending
                i.review_note AS review_note
            FROM document_request_items i
            WHERE i.request_id IN (SELECT id FROM document_requests WHERE application_id = :appId)

            ORDER BY ts DESC, id DESC
            LIMIT 100
            ", [':appId'=>$appId]) ?: [];
        } catch (\Throwable $e) {
            // tablolar henüz yoksa yoksay
            $docRows = [];
        }

        // --- Aktör adları için toplu lookup
        $actorIds = [];
        if (is_array($statusRows)) foreach ($statusRows as $r) $actorIds[] = (int)$r['actor_id'];
        if (is_array($noteRows))   foreach ($noteRows   as $r) if (isset($r['actor_id'])) $actorIds[] = (int)$r['actor_id'];
        if (is_array($auditRows))  foreach ($auditRows  as $r) $actorIds[] = (int)$r['actor_id'];
        if (is_array($docRows))    foreach ($docRows    as $r) if (!empty($r['actor_id'])) $actorIds[] = (int)$r['actor_id'];

        $actorIds = array_values(array_unique(array_filter($actorIds)));
        $actors = [];
        if (!empty($actorIds)) {
            $in = implode(',', array_fill(0, count($actorIds), '?'));
            $urows = $crud->query("
                SELECT id,
                    TRIM(CONCAT(COALESCE(name,''),' ',COALESCE(surname,''))) AS full_name,
                    NULLIF(TRIM(user_image),'') AS user_image
                FROM users
                WHERE id IN ($in)
            ", $actorIds) ?: [];
            foreach ($urows as $u) {
                $actors[(int)$u['id']] = [
                    'id'    => (int)$u['id'],
                    'name'  => $u['full_name'] ?: ('User #'.(int)$u['id']),
                    'image' => $u['user_image'] ?: null,
                ];
            }
        }

        // --- Status -> timeline
        $timeline = [];
        if (is_array($statusRows)) {
            foreach ($statusRows as $r) {
                $a = $actors[(int)$r['actor_id']] ?? ['id'=>(int)$r['actor_id'], 'name'=>'User #'.(int)$r['actor_id'], 'image'=>null];
                $timeline[] = [
                    'type'  => 'status',
                    'ts'    => $r['created_at'],
                    'actor' => $a,
                    'data'  => [
                        'from' => $r['old_status'],
                        'to'   => $r['new_status'],
                        'note' => $r['note'] ?? null,
                    ],
                ];
            }
        }

        // --- Notes -> timeline (visibility/published bilgisi varsa işle)
        if (is_array($noteRows)) {
            foreach ($noteRows as $r) {
                $a = $actors[(int)($r['actor_id'] ?? 0)] ?? ['id'=>(int)($r['actor_id'] ?? 0), 'name'=>'User #'.(int)($r['actor_id'] ?? 0), 'image'=>null];
                $visibility = isset($r['visibility']) ? (string)$r['visibility'] : 'company';
                $published  = isset($r['visible_at']) && !empty($r['visible_at']); // bool
                $timeline[] = [
                    'type'  => 'note',
                    'ts'    => $r['created_at'],
                    'actor' => $a,
                    'data'  => [
                        'text'       => $r['note'],
                        'visibility' => $visibility,  // company|applicant
                        'published'  => $published,   // applicant görünümü için anlamlı
                    ],
                ];
            }
        }

        // --- Reviewer assign/unassign -> timeline
        if (is_array($auditRows)) {
            foreach ($auditRows as $r) {
                $a   = $actors[(int)$r['actor_id']] ?? ['id'=>(int)$r['actor_id'], 'name'=>'User #'.(int)$r['actor_id'], 'image'=>null];
                $act = (string)$r['action'];
                $meta = json_decode((string)$r['meta'], true) ?: [];
                $rid  = isset($meta['reviewer_id']) ? (int)$meta['reviewer_id'] : null;
                $rname= $meta['reviewer_name'] ?? null;

                $type = (stripos($act, 'unassign') !== false) ? 'unassign' : 'assign';
                $timeline[] = [
                    'type'  => $type,
                    'ts'    => $r['created_at'],
                    'actor' => $a,
                    'data'  => [
                        'reviewer_id'   => $rid,
                        'reviewer_name' => $rname,
                    ],
                ];
            }
        }

        // --- Doc Requests / Items -> timeline
        if (is_array($docRows)) {
            foreach ($docRows as $r) {
                $actor = $actors[(int)($r['actor_id'] ?? 0)] ?? ['id'=>0,'name'=>'System','image'=>null];
                if (($r['kind'] ?? '') === 'request') {
                    $timeline[] = [
                        'type'  => 'doc_request',
                        'ts'    => $r['ts'],
                        'actor' => $actor,
                        'data'  => [
                            'status' => $r['req_status'] ?? null, // open|closed
                            'due_at' => $r['due_at']    ?? null,
                        ],
                    ];
                } else {
                    $st = (string)($r['item_status'] ?? 'pending');
                    $timeline[] = [
                        'type'  => ($st === 'approved' ? 'doc_approved' : ($st === 'rejected' ? 'doc_rejected' : 'doc_pending')),
                        'ts'    => $r['ts'],
                        'actor' => $actor,
                        'data'  => [
                            'note' => $r['review_note'] ?? null,
                        ],
                    ];
                }
            }
        }

        // Tarihe göre (ts DESC) sıralayıp dön
        usort($timeline, function($x, $y){
            $tx = $x['ts'] ?? '';
            $ty = $y['ts'] ?? '';
            return strcmp($ty, $tx);
        });

        return ['success'=>true, 'message'=>'OK', 'code'=>200, 'data'=>['items'=>$timeline]];
    }

    public static function app_detail_company(array $p): array
    {
        $auth    = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud    = new Crud($actorId);

        $appId = (int)($p['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['success'=>false, 'message'=>'application_id is required', 'code'=>422];
        }

        // Minimal satır + company_id (izin kontrolü için)
        $app = $crud->read('applications', ['id'=>$appId], [
            'id','company_id','user_id','job_post_id','status',
            'reviewer_user_id','cover_letter','tags','created_at','updated_at'
        ], false);

        if (!$app) {
            return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        }

        $companyId = (int)($app['company_id'] ?? 0);
        if ($companyId <= 0) {
            return ['success'=>false, 'message'=>'Invalid application.company_id', 'code'=>500];
        }

        // Yetki: şirket bağlamında görme
        Gate::check('recruitment.app.view_company', $companyId);

        // JOIN'li detay
        $row = $crud->query("
            SELECT 
                a.id, a.user_id, a.company_id, a.job_post_id, a.status,
                a.cover_letter, a.tags, a.created_at, a.updated_at,
                a.reviewer_user_id,
                u.name  AS user_name,     u.surname AS user_surname,  u.user_image AS user_image,
                r.name  AS reviewer_name, r.surname AS reviewer_surname,
                jp.title AS job_title
            FROM applications a
            LEFT JOIN users     u  ON u.id = a.user_id
            LEFT JOIN users     r  ON r.id = a.reviewer_user_id
            LEFT JOIN job_posts jp ON jp.id = a.job_post_id
            WHERE a.id = :id
            LIMIT 1
        ", [':id'=>$appId]);

        $row = (is_array($row) && isset($row[0])) ? $row[0] : $app;

        $userFullName = trim(($row['user_name'] ?? '').' '.($row['user_surname'] ?? ''));
        $reviewerFullName = null;
        if (!empty($row['reviewer_name']) || !empty($row['reviewer_surname'])) {
            $reviewerFullName = trim(($row['reviewer_name'] ?? '').' '.($row['reviewer_surname'] ?? ''));
        }

        $item = [
            'id'               => (int)$row['id'],
            'user_id'          => (int)$row['user_id'],
            'company_id'       => (int)$row['company_id'],
            'job_post_id'      => (int)$row['job_post_id'],
            'status'           => (string)$row['status'],
            'cover_letter'     => $row['cover_letter'] ?? null,
            'tags'             => $row['tags'] ?? null,
            'created_at'       => $row['created_at'] ?? null,
            'updated_at'       => $row['updated_at'] ?? null,
            'reviewer_user_id' => isset($row['reviewer_user_id']) ? (int)$row['reviewer_user_id'] : null,

            // FE yardımcı alanlar
            'job_title'            => $row['job_title'] ?? null,
            'user_full_name'       => $userFullName !== '' ? $userFullName : ('User #'.(int)$row['user_id']),
            'user_image'           => $row['user_image'] ?? null,
            'user_avatar'          => $row['user_image'] ?? null,
            'created_by_full_name' => $userFullName !== '' ? $userFullName : null,
            'created_by_avatar'    => $row['user_image'] ?? null,
            'reviewer_full_name'   => $reviewerFullName,
        ];

        // Read-only görüntüleme için güvenli CV snapshot
        $cvSnapshot = self::build_cv_snapshot($crud, (int)$row['user_id']);

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => [
                'item'        => $item,
                'cv_snapshot' => $cvSnapshot,
                'status_meta' => self::$STATUS_META_CFG,
                'transitions' => self::$STATUS_TRANSITIONS_CFG,
                'default_reset_target' => self::$DEFAULT_RESET_TARGET,
                'reset_targets' => self::$RESET_TARGETS,
            ],
            'code'    => 200,
        ];
    }

    public static function reviewer_candidates(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $cid = (int)($p['company_id'] ?? 0);
        if ($cid <= 0) return ['success'=>false,'message'=>'Invalid company_id','code'=>422];

        // Bu listeyi görmek için atama yetkisi olsun (veya view_company + review? basit tutalım)
        Gate::check('recruitment.app.assign', $cid);

        $q       = trim((string)($p['q'] ?? ''));
        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(50, (int)($p['per_page'] ?? 20)));
        $off     = ($page - 1) * $perPage;

        // Not: permission check’i gerçek sisteminde join ile doğrulayabilirsin.
        // Basit sürüm: company_users + users join; is_active & has review permission
        $params = [':cid' => $cid];
        $qSql = '';
        if ($q !== '') {
            $qSql = " AND (u.name LIKE :q OR u.surname LIKE :q OR CONCAT(u.name,' ',u.surname) LIKE :q) ";
            $params[':q'] = '%'.$q.'%';
        }

        $rows = $crud->query("
            SELECT SQL_CALC_FOUND_ROWS
                cu.user_id,
                TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,''))) AS full_name,
                NULLIF(TRIM(u.user_image),'') AS user_image_name,
                cu.role, cu.status
            FROM company_users cu
            JOIN users u ON u.id = cu.user_id
            WHERE cu.company_id = :cid
            AND cu.status = 'approved'
            -- İstersen burada gerçek yetki denetimini uygula:
            -- AND EXISTS(SELECT 1 FROM user_permissions up WHERE up.user_id=cu.user_id AND up.permission_code='recruitment.app.review')
            $qSql
            ORDER BY full_name ASC
            LIMIT $perPage OFFSET $off
        ", $params) ?: [];

        $total = 0;
        $trow = $crud->query("SELECT FOUND_ROWS() AS t", []);
        if ($trow && isset($trow[0]['t'])) $total = (int)$trow[0]['t'];

        $items = array_map(function($r){
            return [
                'user_id'     => (int)$r['user_id'],
                'full_name'   => $r['full_name'] ?: ('User #'.$r['user_id']),
                'avatar_path' => $r['user_image_name'] ? ('uploads/user/user/'.$r['user_image_name']) : null,
                'role'        => $r['role'],
            ];
        }, $rows);

        return [
            'success'=>true,
            'message'=>'OK',
            'data'=>[
                'items'=>$items,
                'total'=>$total,
                'page'=>$page,
                'per_page'=>$perPage,
            ],
            'code'=>200
        ];
    }

    public static function reviewer_positions(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $cid = (int)($p['company_id'] ?? 0);
        if ($cid <= 0) return ['success'=>false,'message'=>'Invalid company_id','code'=>422];

        Gate::check('recruitment.app.assign', $cid);

        $rows = $crud->query("
            SELECT DISTINCT
                cp.id   AS position_id,
                cp.name AS name
            FROM company_users cu
            JOIN company_positions cp ON cp.id = cu.position_id
            WHERE cu.company_id = :cid
            AND cu.status     = 'approved'
            AND cu.is_active  = 1
            AND cu.position_id IS NOT NULL
            AND cp.area       = 'office'
            ORDER BY COALESCE(cp.sort, 999999), cp.name
        ", [':cid'=>$cid]) ?: [];

        return ['success'=>true,'message'=>'OK','data'=>['items'=>$rows],'code'=>200];
    }

    public static function reviewer_candidates_by_position(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $cid  = (int)($p['company_id'] ?? 0);
        $pid  = (int)($p['position_id'] ?? 0);
        $q    = trim((string)($p['q'] ?? ''));
        $page = max(1, (int)($p['page'] ?? 1));
        $pp   = max(1, min(50, (int)($p['per_page'] ?? 20)));
        $off  = ($page - 1) * $pp;

        if ($cid <= 0 || $pid <= 0) return ['success'=>false,'message'=>'Invalid params','code'=>422];
        Gate::check('recruitment.app.assign', $cid);

        $params = [':cid'=>$cid, ':pid'=>$pid];
        $qSql = '';
        if ($q !== '') {
            $qSql = " AND (u.name LIKE :q OR u.surname LIKE :q OR CONCAT(u.name,' ',u.surname) LIKE :q) ";
            $params[':q'] = '%'.$q.'%';
        }

        $rows = $crud->query("
            SELECT SQL_CALC_FOUND_ROWS
                cu.user_id,
                TRIM(CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,''))) AS full_name,
                NULLIF(TRIM(u.user_image), '') AS user_image_name,
                cu.rank AS role_label
            FROM company_users cu
            JOIN users u           ON u.id = cu.user_id
            JOIN company_positions cp ON cp.id = cu.position_id
            WHERE cu.company_id = :cid
            AND cu.status     = 'approved'
            AND cu.is_active  = 1
            AND cu.position_id = :pid
            AND cp.area       = 'office'
            $qSql
            ORDER BY full_name ASC
            LIMIT $pp OFFSET $off
        ", $params) ?: [];

        $tot = 0;
        $r2 = $crud->query("SELECT FOUND_ROWS() AS t", []);
        if ($r2 && isset($r2[0]['t'])) $tot = (int)$r2[0]['t'];

        $items = array_map(function($r) {
            $rel = $r['user_image_name'] ? ('uploads/user/user/'.$r['user_image_name']) : null;
            return [
                'user_id'     => (int)$r['user_id'],
                'full_name'   => $r['full_name'] ?: ('User #'.$r['user_id']),
                'avatar_path' => $rel,                  // Flutter: base + this
                'role'        => $r['role_label'] ?? '',// opsiyonel
            ];
        }, $rows);

        return ['success'=>true,'message'=>'OK','data'=>[
            'items'=>$items,
            'total'=>$tot,
            'page'=>$page,
            'per_page'=>$pp
        ],'code'=>200];
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

        try {
            Gate::check('recruitment.post.archive', (int)$post['company_id']);
        } catch (\Throwable $e) {
            Gate::check('recruitment.post.close', (int)$post['company_id']);
        }

        if ((string)$post['status'] !== 'closed') {
            return ['success'=>false, 'message'=>'Only closed posts can be archived', 'code'=>409];
        }

        $ok = $crud->update('job_posts', [
            'status'      => 'archived',
            'archived_at' => self::nowUtc(),   // ✅ eklendi
            'updated_at'  => self::nowUtc(),
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

    public static function post_public_published_count(array $p): array
    {
        $crud = new Crud();
        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        $rows = $crud->count('job_posts', ['company_id' => $companyId['ok'], 'status' => 'published']);

        $total = (int)($rows ?? 0);
        Logger::info("Public published posts count for company_id={$companyId['ok']} is $total");
        return ['success' => true, 'data' => ['total' => $total], 'message' => 'Public published posts count',  'code' => 200];
    }

    public static function post_stats(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $companyId = self::require_int($p['company_id'] ?? null, 'company_id');
        if (isset($companyId['success'])) return $companyId;

        // Şirket içi ilanları görebilen herkes istatistik de görebilsin
        Gate::check('recruitment.post.view', $companyId['ok']);

        $rows = $crud->query("SELECT status, COUNT(*) as c FROM job_posts WHERE company_id = :cid GROUP BY status", [':cid' => $companyId['ok']]);

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

    public static function app_detail_mine(array $p): array {
        return self::detail_mine($p);
    }   
    public static function list_mine(array $p): array {
        $auth    = Auth::requireAuth();
        $userId  = (int)$auth['user_id'];

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = min(100, in_array((int)($p['per_page'] ?? 25), [25,50,100]) ? (int)$p['per_page'] : 25);
        $offset  = ($page - 1) * $perPage;

        // Çoklu status desteği
        $statusParam = $p['status'] ?? null;
        $statuses = is_array($statusParam)
            ? array_values(array_filter($statusParam, fn($s)=>$s!=='' && $s!==null))
            : ((isset($statusParam) && $statusParam!=='') ? [(string)$statusParam] : []);

        $q        = isset($p['q']) ? trim((string)$p['q']) : '';
        $company  = isset($p['company_id'])   ? (int)$p['company_id']   : null;
        $jobPost  = isset($p['job_post_id'])  ? (int)$p['job_post_id']  : null;

        $crud  = new Crud($userId);
        $w     = ["a.user_id = :uid"];
        $bind  = [':uid' => $userId];

        if ($company) { $w[] = "a.company_id = :cid";   $bind[':cid']   = $company; }
        if ($jobPost) { $w[] = "a.job_post_id = :jpid"; $bind[':jpid']  = $jobPost; }

        if ($statuses) {
            $ph = [];
            foreach ($statuses as $i=>$st) { $k=":s{$i}"; $ph[]=$k; $bind[$k]=$st; }
            $w[] = "a.status IN (".implode(',', $ph).")";
        }

        if ($q !== '') {
            // FULLTEXT (title, description) + cover_letter/tags LIKE
            $w[]  = "( MATCH(j.title, j.description) AGAINST (:q IN NATURAL LANGUAGE MODE)
                    OR a.cover_letter LIKE :qlike
                    OR a.tags        LIKE :qlike )";
            $bind[':q']     = $q;
            $bind[':qlike'] = '%'.$q.'%';
        }

        $whereSql = 'WHERE '.implode(' AND ', $w);

        // total
        $sqlCount = "SELECT COUNT(*) AS c
                    FROM applications a
                    LEFT JOIN job_posts j ON j.id = a.job_post_id
                    $whereSql";
        $countRow = $crud->query($sqlCount, $bind);
        $total    = (int) ((is_array($countRow[0] ?? null) ? $countRow[0]['c'] ?? 0 : ($countRow['c'] ?? 0)));

        // items
        $sql = "SELECT
                    a.id, a.job_post_id, a.company_id, a.status, a.created_at, a.updated_at,
                    j.title AS job_title,
                    c.name  AS company_name
                FROM applications a
                LEFT JOIN job_posts j ON j.id = a.job_post_id
                LEFT JOIN companies c ON c.id = a.company_id
                $whereSql
                ORDER BY a.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $items = $crud->query($sql, $bind) ?: [];

        return [
            'success'=>true, 'message'=>'OK', 'code'=>200,
            'data'=>[
                'items'=>$items,
                'page'=>$page, 'per_page'=>$perPage,
                'total'=>$total, 'pages'=>(int)ceil($total / $perPage),
            ]
        ];
    }

    public static function detail_mine(array $params): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $appId  = (int)($params['application_id'] ?? 0);
        if ($appId <= 0) {
            return ['success'=>false, 'message'=>'application_id is required', 'code'=>422];
        }

        $crud = new Crud($userId);

        $row = $crud->query("
            SELECT 
                a.id, a.user_id, a.company_id, a.job_post_id, a.status,
                a.cover_letter, a.tags, a.created_at, a.updated_at,
                j.title AS job_title,
                c.name  AS company_name
            FROM applications a
            LEFT JOIN job_posts j ON j.id = a.job_post_id
            LEFT JOIN companies c ON c.id = a.company_id
            WHERE a.id = :id AND a.user_id = :u
            LIMIT 1
        ", [':id'=>$appId, ':u'=>$userId]);

        if (!$row || !is_array($row) || empty($row)) {
            return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        }
        $application = is_array($row[0] ?? null) ? $row[0] : $row;

        $history = $crud->query("
            SELECT old_status, new_status, changed_by, note, created_at
            FROM application_status_history
            WHERE application_id = :id
            ORDER BY created_at DESC, id DESC
        ", [':id'=>$appId]) ?: [];

        return [
            'success'=>true, 'message'=>'OK', 'code'=>200,
            'data'=>[
                'application'=>$application,
                'status_history'=>$history,
                'notes'=>[] // ileride visibility ile açılabilir
            ]
        ];
    }

    /**
     * Başvurumu geri çek (withdraw)
     * params: application_id:int
     * Kural: sadece sahibi + withdraw yapılabilir statüler
     */
    public static function withdraw(array $params): array
    {
        $auth   = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $appId  = (int)($params['application_id'] ?? 0);

        if ($appId <= 0) {
            return ['success'=>false, 'message'=>'application_id is required', 'code'=>422];
        }

        $crud = new Crud($userId);

        // Mevcut kayıt + sahiplik
        $app = $crud->read('applications', [
            ['id', '=', $appId],
            ['user_id', '=', $userId],
        ], ['id','user_id','company_id','job_post_id','status'], false);

        if (!$app || !is_array($app)) {
            return ['success'=>false, 'message'=>'Application not found', 'code'=>404];
        }

        $current = (string)$app['status'];
        $blocked = ['hired','rejected','withdrawn']; // terminal statüler

        if (in_array($current, $blocked, true)) {
            return [
                'success'=>false,
                'message'=>"Application cannot be withdrawn from status: {$current}",
                'code'=>409
            ];
        }

        // 1) applications.status = 'withdrawn'
        $ok = $crud->update('applications', [
            'status'     => 'withdrawn',
            'updated_at' => date('Y-m-d H:i:s'),
        ], [
            ['id', '=', $appId],
            ['user_id', '=', $userId],
            ['status', '=', $current],
        ]);

        if (!$ok) {
            return ['success'=>false, 'message'=>'Application changed by another action, please refresh', 'code'=>409];
        }

        // 2) history kaydı
        $crud->create('application_status_history', [
            'application_id' => (int)$appId,
            'old_status'     => $current,
            'new_status'     => 'withdrawn',
            'changed_by'     => $userId,
            'note'           => 'withdraw by candidate',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        // 3) audit
        $crud->create('audit_events', [
            'actor_id'   => $userId,
            'entity_type'=> 'application',
            'entity_id'  => (int)$appId,
            'action'     => 'withdraw',
            'meta'       => json_encode(['from'=>$current,'to'=>'withdrawn'], JSON_UNESCAPED_UNICODE),
            'ip'         => $_SERVER['REMOTE_ADDR']   ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success'=>true,
            'message'=>'Application withdrawn',
            'code'=>200,
            'data'=>[
                'id'     => (int)$appId,
                'status' => 'withdrawn'
            ]
        ];
    }
// --- add ALONGSIDE other public static action methods ---

    public static function cv_profile_percent(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        // Basit bir skorlamayla yüzde hesapla
        $user = $crud->read('users', ['id' => (int)$auth['user_id']], false) ?: [];
        $cv   = $crud->read('user_cv', ['user_id' => (int)$auth['user_id']], false) ?: [];

        $decodeJson = function ($v) {
            if ($v === null || $v === '') return [];
            if (is_array($v)) return $v;
            $d = json_decode((string)$v, true);
            return is_array($d) ? $d : [];
        };

        // Kriterler (esnek, dilediğinde ağırlıkları değiştirebilirsin)
        $checklist = [
            'name'        => (trim((string)($user['name'] ?? '')) !== ''),
            'surname'     => (trim((string)($user['surname'] ?? '')) !== ''),
            'email'       => (trim((string)($user['email'] ?? '')) !== ''),
            'title'       => (trim((string)($cv['professional_title'] ?? '')) !== ''),
            'phones'      => count($decodeJson($cv['phone'] ?? null)) > 0,
            'languages'   => count($decodeJson($cv['language'] ?? null)) > 0,
            'skills'      => count($decodeJson($cv['skills'] ?? null)) > 0,
            'experience'  => count($decodeJson($cv['work_experience'] ?? null)) > 0,
            'education'   => count($decodeJson($cv['education'] ?? null)) > 0,
            'certs'       => count($decodeJson($cv['certificates'] ?? null)) > 0,
            'references'  => count($decodeJson($cv['references'] ?? null)) > 0,
        ];

        $labels = [
            'name'       => 'First name',
            'surname'    => 'Last name',
            'email'      => 'Email',
            'title'      => 'Professional title',
            'phones'     => 'Phone',
            'languages'  => 'Languages',
            'skills'     => 'Skills',
            'experience' => 'Work experience',
            'education'  => 'Education',
            'certs'      => 'Certificates',
            'references' => 'References',
        ];

        $total = count($checklist);
        $hit   = 0;
        $missing = [];
        foreach ($checklist as $k => $ok) {
            if ($ok) $hit++; else $missing[] = $labels[$k] ?? $k;
        }

        $percent = ($total > 0) ? (int)round($hit * 100 / $total) : 0;

        // Minimum eşiği projede 50 kabul etmiştik
        $data = [
            'percent'        => $percent,
            'required_min'   => 50,
            'missing_labels' => $missing,
            'strategy'       => 'v1-basic',
        ];

        Response::ok($data, 'OK', 200);
        return []; // unreachable (ok exits), imza için
    }

    public static function job_post_close(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        // Paramlar
        $companyId = (int)($p['company_id'] ?? 0);
        $jobPostId = (int)($p['job_post_id'] ?? 0);
        if ($companyId <= 0 || $jobPostId <= 0) {
            return ['success'=>false,'message'=>'company_id and job_post_id are required','code'=>422];
        }

        // Yetki
        Gate::check('recruitment.job.close', $companyId);

        // Kapatma
        $now = date('Y-m-d H:i:s');
        $ok  = $crud->update('job_posts', [
            'status'    => 'closed',
            'closed_at' => $now,
            'updated_at'=> $now,
        ], ['id'=>$jobPostId, 'company_id'=>$companyId]);

        if (!$ok) {
            return ['success'=>false,'message'=>'Job post close failed','code'=>500];
        }

        self::revokeSharesForClosedPost($crud, (int)$jobPostId);

        // (Opsiyonel) audit
        self::audit($crud, (int)$auth['user_id'], 'job_post', $jobPostId, 'close', [
            'company_id'=>$companyId,
        ]);

        return ['success'=>true,'message'=>'Job post closed','data'=>['id'=>(int)$jobPostId],'code'=>200];
    }

    // === [Notes helpers] ===
    private static function normalize_visibility(?string $v): string {
        $v = strtolower(trim((string)$v));
        return in_array($v, ['company','applicant'], true) ? $v : 'company';
    }

    // Adayın görebileceği mi?
    private static function is_applicant_visible(array $row): bool {
        return ($row['visibility'] ?? 'company') === 'applicant'
            && empty($row['deleted_at']);
    }

    // Sadece yayınlanmamış notlar silinebilir/düzenlenebilir
    private static function can_mutate_note(array $row): bool {
        return empty($row['visible_at']) && empty($row['deleted_at']);
    }

    private static function revokeSharesForClosedPost(Crud $crud, int $jobPostId): void
    {
        try {
            $crud->query("
                UPDATE user_document_shares s
                JOIN applications a ON s.grantee_type = 'application' AND s.grantee_id = a.id
                JOIN job_posts   jp ON jp.id = a.job_post_id
                SET s.revoked_at = NOW()
                WHERE jp.id = :pid
                    AND jp.closed_at IS NOT NULL
                    AND jp.closed_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND s.revoked_at IS NULL
            ", [':pid' => $jobPostId]);
        } catch (\Throwable $e) {
            Logger::error('revokeSharesForClosedPost_fail: '.$e->getMessage());
        }
    }

    private static function idsOfCompanyReviewers(Crud $crud, int $companyId, ?int $excludeUserId = null): array
    {
        $rows = $crud->query("
            SELECT DISTINCT cu.user_id
            FROM company_users cu
            JOIN company_positions cp ON cp.id = cu.position_id
            WHERE cu.company_id = :cid
            AND cu.status     = 'approved'
            AND cu.is_active  = 1
            AND cu.position_id IS NOT NULL
            AND cp.area       = 'office'
        ", [':cid'=>$companyId]) ?: [];

        $ids = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if ($excludeUserId !== null && $uid === (int)$excludeUserId) continue;
            $ids[] = $uid;
        }
        return array_values(array_unique($ids));
    }
}
