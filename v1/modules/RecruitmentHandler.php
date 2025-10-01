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

    private static function build_cv_snapshot(Crud $crud, int $userId): array
    {
        $user = $crud->read('users', ['id' => $userId], false) ?: [];
        $cv   = $crud->read('user_cv', ['user_id' => $userId], false) ?: [];

        // decode known JSON-ish columns safely
        $decodeJson = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_array($v)) return $v;
            $d = json_decode((string)$v, true);
            return is_array($d) ? $d : null;
        };

        return [
            'user' => [
                'id'        => $user['id'] ?? null,
                'name'      => $user['name'] ?? null,
                'surname'   => $user['surname'] ?? null,
                'email'     => $user['email'] ?? null,
                'dob'       => $user['dob'] ?? null,
                'gender'    => $user['gender'] ?? null,
                'bio'       => $user['bio'] ?? null,
                'image'     => $user['user_image'] ?? null,
                'cover'     => $user['cover_image'] ?? null,
            ],
            'cv' => [
                'basic_info'         => $cv['basic_info'] ?? null,
                'professional_title' => $cv['professional_title'] ?? null,
                'country_id'         => isset($cv['country_id']) ? (int)$cv['country_id'] : null,
                'city_id'            => isset($cv['city_id']) ? (int)$cv['city_id'] : null,
                'address'            => $cv['address'] ?? null,
                'zip_code'           => $cv['zip_code'] ?? null,
                'phone'              => $decodeJson($cv['phone']) ?? null,
                'social'             => $decodeJson($cv['social']) ?? null,
                'email'              => $decodeJson($cv['email']) ?? null,
                'language'           => $decodeJson($cv['language']) ?? null,
                'education'          => $decodeJson($cv['education']) ?? null,
                'work_experience'    => $decodeJson($cv['work_experience']) ?? null,
                'skills'             => $decodeJson($cv['skills']) ?? null,
                'certificates'       => $decodeJson($cv['certificates']) ?? null,
                'seafarer_info'      => $decodeJson($cv['seafarer_info']) ?? null,
                'references'         => $decodeJson($cv['references']) ?? null,
                'access_scope'       => $cv['access_scope'] ?? null,
                'updated_at'         => $cv['updated_at'] ?? null,
            ],
            'captured_at' => gmdate('Y-m-d H:i:s'),
            'schema'      => 'v1',
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

        $area = isset($p['area']) ? trim((string)$p['area']) : 'crew';
        $allowedAreas = ['crew','office','port','shipyard','supplier','agency'];
        if (!in_array($area, $allowedAreas, true)) $area = 'crew';

        $description = isset($p['description']) ? (string)$p['description'] : null;
        $positionId  = isset($p['position_id']) ? (int)$p['position_id'] : null;
        $location    = isset($p['location']) ? trim((string)$p['location']) : null;

        // --- Yeni alanlar
        $employmentType = self::sanitize_enum($p['employment_type'] ?? null, [
            'full_time','part_time','contract','seasonal','internship','temporary','other'
        ]);

        $ageMin  = self::to_int_or_null($p['age_min']  ?? null);
        $ageMax  = self::to_int_or_null($p['age_max']  ?? null);
        if ($ageMin !== null && $ageMin < 0) $ageMin = 0;
        if ($ageMax !== null && $ageMax < 0) $ageMax = 0;
        if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
            return ['success'=>false,'message'=>'age_min cannot be greater than age_max','code'=>422];
        }

        $salaryMin = self::to_decimal_or_null($p['salary_min'] ?? null);
        $salaryMax = self::to_decimal_or_null($p['salary_max'] ?? null);
        if ($salaryMin !== null && $salaryMin < 0) $salaryMin = 0;
        if ($salaryMax !== null && $salaryMax < 0) $salaryMax = 0;
        if ($salaryMin !== null && $salaryMax !== null && $salaryMin > $salaryMax) {
            return ['success'=>false,'message'=>'salary_min cannot be greater than salary_max','code'=>422];
        }

        $salaryCurrency = self::sanitize_currency($p['salary_currency'] ?? null);
        $salaryRateUnit = self::sanitize_enum($p['salary_rate_unit'] ?? null, [
            'hour','day','month','year','contract','trip'
        ]);

        $contractMonths  = self::to_int_or_null($p['contract_duration_months'] ?? null);
        $probationMonths = self::to_int_or_null($p['probation_months'] ?? null);

        $rotOn  = self::to_int_or_null($p['rotation_on_months']  ?? null);
        $rotOff = self::to_int_or_null($p['rotation_off_months'] ?? null);

        $bonusType  = self::sanitize_enum($p['rotation_bonus_type'] ?? null, ['none','fixed','one_salary','percent']) ?? 'none';
        $bonusValue = self::to_decimal_or_null($p['rotation_bonus_value'] ?? null);
        if ($bonusType === 'fixed' && ($bonusValue === null || $bonusValue <= 0)) {
            return ['success'=>false,'message'=>'rotation_bonus_value must be positive for fixed bonus','code'=>422];
        }
        if ($bonusType === 'percent') {
            if ($bonusValue === null || $bonusValue < 0 || $bonusValue > 100) {
                return ['success'=>false,'message'=>'rotation_bonus_value must be between 0 and 100 for percent bonus','code'=>422];
            }
        }
        if ($bonusType === 'one_salary') $bonusValue = null;

        $cityId = self::to_int_or_null($p['city_id'] ?? null);

        $benefitsJson    = self::normalize_json($p['benefits_json'] ?? null);
        $obligationsJson = self::normalize_json($p['obligations_json'] ?? null);
        $requirementsJson= self::normalize_json($p['requirements_json'] ?? null);

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

    public static function post_save_and_publish(array $p): array
    {
        $auth = Auth::requireAuth();
        $actorId = (int)$auth['user_id'];
        $crud = new Crud($actorId);

        $id = (int)($p['id'] ?? 0);
        if ($id <= 0) {
            return ['success'=>false,'message'=>'Invalid id','code'=>422];
        }

        // mevcut kayıt
        $post = $crud->read('job_posts', ['id'=>$id], ['*'], false);
        if (!$post) {
            return ['success'=>false,'message'=>'Not found','code'=>404];
        }

        // izin ve state
        Gate::check('recruitment.post.publish', (int)$post['company_id']);
        if ((string)$post['status'] !== 'draft') {
            return ['success'=>false,'message'=>'Invalid state for publish','code'=>409];
        }

        // --- post_update ile aynı mantıkta alan hazırlığı ---
        $data = [];

        // string alanlar
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

        // int alanlar
        $mapInt = [
            'position_id','age_min','age_max','contract_duration_months',
            'probation_months','rotation_on_months','rotation_off_months','city_id'
        ];
        foreach ($mapInt as $k) {
            if (array_key_exists($k, $p)) {
                $data[$k] = self::to_int_or_null($p[$k]);
            }
        }

        // decimal alanlar
        foreach (['salary_min','salary_max','rotation_bonus_value'] as $k) {
            if (array_key_exists($k, $p)) {
                $data[$k] = self::to_decimal_or_null($p[$k]);
            }
        }

        // mantık kontrolleri
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

        // JSON alanlar
        foreach (['benefits_json','obligations_json','requirements_json'] as $jk) {
            if (array_key_exists($jk, $p)) {
                $data[$jk] = self::normalize_json($p[$jk]);
            }
        }

        // publish için minimal zorunlu alanlar
        $mustHave = ['title']; // gerekirse 'description' vb. eklenebilir
        foreach ($mustHave as $mk) {
            $current = array_key_exists($mk, $data) ? $data[$mk] : ($post[$mk] ?? null);
            if (!$current || trim((string)$current) === '') {
                return ['success'=>false,'message'=>"$mk is required for publish",'code'=>422];
            }
        }

        // --- transaction: update + publish ---
        $pdo = DB::getInstance();
        try {
            $pdo->beginTransaction();

            if (!empty($data)) {
                $data['updated_at'] = self::nowUtc();
                $ok = $crud->update('job_posts', $data, ['id'=>$id]);
                if (!$ok) {
                    $pdo->rollBack();
                    return ['success'=>false,'message'=>'Update failed','code'=>500];
                }
            }

            $ok2 = $crud->update('job_posts', [
                'status'       => 'published',
                'published_at' => self::nowUtc(),
                'updated_at'   => self::nowUtc(),
            ], ['id'=>$id]);

            if (!$ok2) {
                $pdo->rollBack();
                return ['success'=>false,'message'=>'Publish failed','code'=>500];
            }

            $pdo->commit();

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('post_save_and_publish failed', ['e'=>$e->getMessage()]);
            return ['success'=>false,'message'=>'Transaction failed','code'=>500];
        }

        self::audit($crud, $actorId, 'job_post', (int)$id, 'save_and_publish', ['updated_fields'=>array_keys($data)]);

        return ['success'=>true,'message'=>'Saved and published','data'=>['id'=>$id,'status'=>'published'],'code'=>200];
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
        $row = $crud->read('job_posts', ['id'=>$id], ['id','company_id'], false);
        if (!$row) {
            return ['success'=>false,'message'=>'Not found','code'=>404];
        }
        $companyId = (int)$row['company_id'];
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

        return ['success'=>true,'message'=>'Updated','data'=>['id'=>$id],'code'=>200];
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
            'closed_at'  => self::nowUtc(),   // ✅ eklendi
            'updated_at' => self::nowUtc(),
        ], ['id'=>$id['ok']]);

        if (!$ok) return ['success'=>false, 'message'=>'Close failed', 'code'=>500];

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

        $sql = "
            SELECT SQL_CALC_FOUND_ROWS *
            FROM job_posts
            WHERE $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $off
        ";

        // ---- teşhis logları
        Logger::info("Recruitment.post_list mode=" . ($isInternalViewer ? 'internal' : 'public')
            . " company_id=" . ($companyId ?? 'NULL')
            . " status=" . ($status ?? 'NULL')
            . " q='$q'");

        Logger::info("SQL: $sql ; PARAMS: " . json_encode($params, JSON_UNESCAPED_UNICODE));

        $rows  = $crud->query($sql, $params);
        $total = (int)($crud->query("SELECT FOUND_ROWS() AS t")[0]['t'] ?? 0);

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
            // İsterseniz şunları da ekleyin (kolonlar varsa):
            // 'cover_letter' => isset($p['cover_letter']) ? (string)$p['cover_letter'] : null,
            // 'cv_snapshot'  => isset($p['cv_snapshot']) ? json_encode($p['cv_snapshot']) : null,
            // 'attachments'  => isset($p['attachments']) ? json_encode($p['attachments']) : null,
        ]);

        if (!$id) return ['success'=>false,'message'=>'Create failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'application', (int)$id, 'create', [
            'job_post_id' => (int)$pid['ok'],
            'user_id'     => $uid,
        ]);

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
}
