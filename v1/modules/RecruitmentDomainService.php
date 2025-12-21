<?php
// v1/modules/RecruitmentDomainService.php

require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/RecruitmentMessageService.php';

class RecruitmentDomainService
{
    /*
     * Merkez tablo isimleri
     */
    private const T_JOB_POSTS = 'job_posts';
    private const T_APPS = 'applications';
    private const T_APP_STATUS = 'application_status_history';
    private const T_APP_MESSAGES = 'application_messages';   // bu tabloyu sen oluşturacaksın
    private const T_APP_NOTES = 'application_notes';

    /*
     * Job statusleri
     */
    private const S_JOB_DRAFT = 'draft';
    private const S_JOB_PUBLISHED = 'published';
    private const S_JOB_CLOSED = 'closed';
    private const S_JOB_ARCHIVED = 'archived';
    private const T_COMPANIES = 'companies';
    private const T_CERTIFICATES = 'certificates';


    /*
     * Application statusleri
     */
    private const S_APP_SUBMITTED = 'submitted';
    private const S_APP_UNDER_REVIEW = 'under_review';
    private const S_APP_PENDING_DOCUMENTS = 'pending_documents';
    private const S_APP_IN_COMMUNICATION = 'in_communication';
    private const S_APP_OFFER_SENT = 'offer_sent';
    private const S_APP_HIRED = 'hired';
    private const S_APP_REJECTED = 'rejected';
    private const S_APP_WITHDRAWN = 'withdrawn';
    private const S_APP_OFFER_DECLINED = 'offer_declined';

    /*
     * ---------------------------------------------------------------------
     * KÜÇÜK HELPER’LAR
     * ---------------------------------------------------------------------
     */

    private static function makeCrud(?int $userId, bool $guard = true): Crud
    {
        // Crud::__construct(?int $userId = null, bool $permissionGuard = true)
        return new Crud($userId, $guard);
    }

    private static function getOne(Crud $crud, string $table, array $where): ?array
    {
        $row = $crud->read($table, $where, ['*'], false);
        return $row === false ? null : $row;
    }

    private static function paginate(
        Crud $crud,
        string $table,
        array $where,
        int $page,
        int $perPage,
        array $orderBy = []
    ): array {
        $offset = ($page - 1) * $perPage;

        $items = $crud->read(
            $table,
            $where,
            ['*'],
            true,
            $orderBy,
            [],
            [
                'limit' => $perPage,
                'offset' => $offset,
            ]
        );
        if ($items === false) {
            $items = [];
        }

        $total = $crud->count($table, $where);
        if ($total === false) {
            $total = 0;
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function addStatusHistory(
        Crud $crud,
        int $applicationId,
        string $fromStatus,
        string $toStatus,
        int $actorUserId,
        ?string $reason = null
    ): void {
        $crud->create(self::T_APP_STATUS, [
            'application_id' => $applicationId,
            'old_status' => $fromStatus,
            'new_status' => $toStatus,
            'changed_by' => $actorUserId,
            'note' => $reason,
            'created_at' => self::now(),
        ]);
    }

    private static function changeAppStatus(
        Crud $crud,
        array $app,
        string $newStatus,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $applicationId = (int) $app['id'];
        $oldStatus = (string) $app['status'];

        if ($oldStatus === $newStatus) {
            return $app;
        }

        $ok = $crud->update(
            self::T_APPS,
            [
                'status' => $newStatus,
                'updated_at' => self::now(),
            ],
            ['id' => $applicationId]
        );

        if ($ok) {
            self::addStatusHistory($crud, $applicationId, $oldStatus, $newStatus, $actorUserId, $reason);
            $updated = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
            if ($updated) {
                return $updated;
            }
        }

        Logger::error('Application status update failed', [
            'application_id' => $applicationId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return $app;
    }

    private static function validateStatusTransition(string $from, string $to): void
    {
        $map = [
            self::S_APP_SUBMITTED => [
                self::S_APP_UNDER_REVIEW,
                self::S_APP_REJECTED,
                self::S_APP_WITHDRAWN,
            ],
            self::S_APP_UNDER_REVIEW => [
                self::S_APP_IN_COMMUNICATION,
                self::S_APP_PENDING_DOCUMENTS,
                self::S_APP_OFFER_SENT,
                self::S_APP_REJECTED,
            ],
            self::S_APP_PENDING_DOCUMENTS => [
                self::S_APP_IN_COMMUNICATION,
                self::S_APP_OFFER_SENT,
                self::S_APP_REJECTED,
            ],
            self::S_APP_IN_COMMUNICATION => [
                self::S_APP_OFFER_SENT,
                self::S_APP_REJECTED,
            ],
            self::S_APP_OFFER_SENT => [
                self::S_APP_HIRED,
                self::S_APP_OFFER_DECLINED,
            ],
            self::S_APP_WITHDRAWN => [],
            self::S_APP_REJECTED => [],
            self::S_APP_HIRED => [],
            self::S_APP_OFFER_DECLINED => [],
        ];

        if (!isset($map[$from])) {
            throw new RuntimeException("Invalid from status: {$from}");
        }
        if (!in_array($to, $map[$from], true)) {
            throw new RuntimeException("Status transition not allowed: {$from} -> {$to}");
        }
    }

    /*
     * -------------------------------------------------------------------------
     * JOB POSTS
     * -------------------------------------------------------------------------
     */

    public static function create_job_post(int $companyId, int $actorUserId, array $data): array
    {
        $crud = self::makeCrud($actorUserId);

        $insert = [
            'company_id' => $companyId,
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => (string) ($data['description'] ?? ''),
            'location' => (string) ($data['location'] ?? ''),
            'employment_type' => (string) ($data['employment_type'] ?? ''),
            'requirements' => (string) ($data['requirements'] ?? ''),
            'salary_range' => $data['salary_range'] ?? null,
            'max_hires' => !empty($data['max_hires']) ? (int) $data['max_hires'] : null,
            'status' => self::S_JOB_DRAFT,
            'created_by' => $actorUserId,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ];

        if ($insert['title'] === '') {
            throw new InvalidArgumentException('title is required');
        }

        $id = $crud->create(self::T_JOB_POSTS, $insert);
        if ($id === false) {
            throw new RuntimeException('Job post insert failed');
        }

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $id]);
        return $job ?? ['id' => $id];
    }

    public static function update_job_post(int $jobPostId, int $actorUserId, array $data): array
    {
        $crud = self::makeCrud($actorUserId);

        $existing = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        if (!$existing) {
            throw new RuntimeException('Job post not found');
        }

        $update = [];
        foreach (['title', 'description', 'location', 'employment_type', 'requirements', 'salary_range', 'max_hires'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (!$update) {
            return $existing; // değişiklik yok → mevcut kaydı döndür
        }
        $update['updated_at'] = self::now();

        $ok = $crud->update(self::T_JOB_POSTS, $update, ['id' => $jobPostId]);
        if (!$ok) {
            throw new RuntimeException('Job post update failed');
        }

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        return $job ?? $existing;
    }

    public static function publish_job_post(int $jobPostId, int $actorUserId): array
    {
        $crud = self::makeCrud($actorUserId);

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        if (!$job) {
            throw new RuntimeException('Job post not found');
        }

        if ($job['status'] !== self::S_JOB_DRAFT) {
            return $job; // şimdilik sessizce dön
        }

        $ok = $crud->update(self::T_JOB_POSTS, [
            'status' => self::S_JOB_PUBLISHED,
            'updated_at' => self::now(),
        ], ['id' => $jobPostId]);

        if (!$ok) {
            throw new RuntimeException('Job post publish failed');
        }

        return self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]) ?? $job;
    }

    public static function close_job_post(int $jobPostId, int $actorUserId): array
    {
        $crud = self::makeCrud($actorUserId);

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        if (!$job) {
            throw new RuntimeException('Job post not found');
        }

        $ok = $crud->update(self::T_JOB_POSTS, [
            'status' => self::S_JOB_CLOSED,
            'updated_at' => self::now(),
        ], ['id' => $jobPostId]);

        if (!$ok) {
            throw new RuntimeException('Job post close failed');
        }

        return self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]) ?? $job;
    }

    public static function archive_job_post(int $jobPostId, int $actorUserId): array
    {
        $crud = self::makeCrud($actorUserId);

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        if (!$job) {
            throw new RuntimeException('Job post not found');
        }

        $ok = $crud->update(self::T_JOB_POSTS, [
            'status' => self::S_JOB_ARCHIVED,
            'updated_at' => self::now(),
        ], ['id' => $jobPostId]);

        if (!$ok) {
            throw new RuntimeException('Job post archive failed');
        }

        return self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]) ?? $job;
    }

    public static function delete_job_post(int $jobPostId, int $actorUserId): bool
    {
        $crud = self::makeCrud($actorUserId);

        $hasApps = $crud->count(self::T_APPS, ['job_post_id' => $jobPostId]);
        if ($hasApps === false) {
            return false;
        }
        if ($hasApps > 0) {
            return false; // başvurusu olan ilan silinmez
        }

        return $crud->delete(self::T_JOB_POSTS, ['id' => $jobPostId]);
    }

    public static function list_company_job_posts(
        int $companyId,
        int $actorUserId,
        int $page,
        int $perPage,
        ?string $status
    ): array {
        $crud = self::makeCrud($actorUserId);

        $where = ['company_id' => $companyId];
        if ($status) {
            $where['status'] = $status;
        }

        return self::paginate(
            $crud,
            self::T_JOB_POSTS,
            $where,
            $page,
            $perPage,
            ['created_at' => 'DESC']
        );
    }

    public static function get_job_post_detail(int $jobPostId, int $actorUserId): array
    {
        $crud = self::makeCrud($actorUserId);

        $job = self::getOne($crud, self::T_JOB_POSTS, ['id' => $jobPostId]);
        if (!$job) {
            throw new RuntimeException('Job post not found');
        }

        $total = $crud->count(self::T_APPS, ['job_post_id' => $jobPostId]);
        if ($total === false) {
            $total = 0;
        }

        $byStatusRows = $crud->read(
            self::T_APPS,
            ['job_post_id' => $jobPostId],
            ['status', 'COUNT(*) as cnt'],
            true,
            [],
            ['status']
        );

        $byStatus = [];
        if (is_array($byStatusRows)) {
            foreach ($byStatusRows as $r) {
                $byStatus[$r['status']] = (int) $r['cnt'];
            }
        }

        return [
            'job_post' => $job,
            'stats' => [
                'applications_total' => $total,
                'applications_by_status' => $byStatus,
            ],
        ];
    }

    public static function get_company_app_stats(
        int $companyId,
        int $actorUserId
    ): array {
        $crud = self::makeCrud($actorUserId);

        // Toplam başvuru
        $total = $crud->count(self::T_APPS, ['company_id' => $companyId]);
        if ($total === false) {
            $total = 0;
        }

        // Statüye göre grup
        $byStatusRows = $crud->read(
            self::T_APPS,
            ['company_id' => $companyId],
            ['status', 'COUNT(*) AS cnt'],
            true,
            [],
            ['status']
        );

        $byStatus = [];
        if (is_array($byStatusRows)) {
            foreach ($byStatusRows as $r) {
                $byStatus[$r['status']] = (int) $r['cnt'];
            }
        }

        return [
            'company_id' => $companyId,
            'applications_total' => $total,
            'applications_by_status' => $byStatus,
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * COMPANY – APPLICATIONS
     * -------------------------------------------------------------------------
     */

    public static function list_job_applications(
        int $jobPostId,
        int $actorUserId,
        int $page,
        int $perPage,
        ?string $status
    ): array {
        $crud = self::makeCrud($actorUserId);

        $where = ['job_post_id' => $jobPostId];
        if ($status) {
            $where['status'] = $status;
        }

        return self::paginate(
            $crud,
            self::T_APPS,
            $where,
            $page,
            $perPage,
            ['created_at' => 'DESC']
        );
    }

    public static function list_company_applications(
        int $companyId,
        int $actorUserId,
        int $page,
        int $perPage,
        ?string $status = null
    ): array {
        Gate::check('recruitment.company.view', $companyId); // 403 yoksa

        $crud = new Crud($actorUserId, true);

        $where = [
            ['company_id', '=', $companyId],
        ];
        if ($status !== null && $status !== '') {
            $where[] = ['status', '=', $status];
        }

        $total = (int) $crud->count('applications', $where);

        $items = $crud->read(
            'applications',
            $where,
            ['id', 'user_id', 'company_id', 'job_post_id', 'status', 'cover_letter', 'created_at', 'updated_at'],
            true,
            ['id' => 'DESC'],
            [],
            ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );
        if ($items === false) {
            throw new \RuntimeException('Company applications could not be loaded');
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'items' => $items,
        ];
    }


    public static function get_application_detail(
        int $applicationId,
        int $actorUserId,
        bool $autoMarkUnderReview = true
    ): array {
        $crud = self::makeCrud($actorUserId);

        $app = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $oldStatus = $app['status'];
        if ($autoMarkUnderReview && $oldStatus === self::S_APP_SUBMITTED) {
            $app = self::changeAppStatus(
                $crud,
                $app,
                self::S_APP_UNDER_REVIEW,
                $actorUserId,
                null
            );

            if (!empty($app['user_id'])) {
                RecruitmentMessageService::notify_candidate(
                    (int) $app['user_id'],
                    'application_under_review',
                    'Application under review',
                    'Your application is under review',
                    ['application_id' => $applicationId]
                );
            }
        }

        $messages = $crud->read(
            self::T_APP_MESSAGES,
            ['application_id' => $applicationId],
            ['*'],
            true,
            ['created_at' => 'ASC']
        ) ?: [];

        $notes = $crud->read(
            self::T_APP_NOTES,
            ['application_id' => $applicationId],
            ['*'],
            true,
            ['created_at' => 'ASC']
        ) ?: [];

        $history = $crud->read(
            self::T_APP_STATUS,
            ['application_id' => $applicationId],
            ['*'],
            true,
            ['created_at' => 'ASC']
        ) ?: [];

        return [
            'application' => $app,
            'messages' => $messages,
            'internal_notes' => $notes,
            'status_history' => $history,
        ];
    }

    public static function send_message_to_applicant(
        int $applicationId,
        int $actorUserId,
        string $messageType,
        string $content
    ): array {
        $crud = self::makeCrud($actorUserId);

        $app = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $candidateId = (int) $app['user_id'];

        // Mesaj tipi normalleştirme
        $messageType = ($messageType === 'doc_request') ? 'doc_request' : 'text';
        $direction = 'company_to_candidate';

        // Mesaj kaydı
        $msgId = $crud->create(self::T_APP_MESSAGES, [
            'application_id' => $applicationId,
            'from_user_id' => $actorUserId,
            'to_user_id' => $candidateId ?: null,
            'direction' => $direction,
            'type' => $messageType,
            'content' => $content,
            'created_at' => self::now(),
        ]);

        if ($msgId === false) {
            throw new RuntimeException('Message insert failed');
        }

        // Status geçişi
        $oldStatus = $app['status'];
        $newStatus = $oldStatus;

        if ($messageType === 'doc_request') {
            if ($oldStatus !== self::S_APP_PENDING_DOCUMENTS) {
                $newStatus = self::S_APP_PENDING_DOCUMENTS;
            }
        } else {
            if (!in_array($oldStatus, [self::S_APP_IN_COMMUNICATION, self::S_APP_PENDING_DOCUMENTS], true)) {
                $newStatus = self::S_APP_IN_COMMUNICATION;
            }
        }

        if ($newStatus !== $oldStatus) {
            $app = self::changeAppStatus($crud, $app, $newStatus, $actorUserId, null);
        }

        // Bildirim
        if ($candidateId) {
            if ($messageType === 'doc_request') {
                $title = 'Company requested documents';
                $body = 'Company requested documents for your application.';
                $notifType = 'documents_requested';
            } else {
                $title = 'New message';
                $body = 'You have a new message regarding your application.';
                $notifType = 'new_message';
            }

            RecruitmentMessageService::notify_candidate(
                $candidateId,
                $notifType,
                $title,
                $body,
                [
                    'application_id' => $applicationId,
                    'message_id' => $msgId,
                ]
            );
        }

        return [
            'message_id' => $msgId,
            'new_status' => $newStatus,
        ];
    }

    public static function add_internal_note(
        int $applicationId,
        int $actorUserId,
        string $content
    ): array {
        $crud = self::makeCrud($actorUserId);

        $id = $crud->create(self::T_APP_NOTES, [
            'application_id' => $applicationId,
            'user_id' => $actorUserId,
            'content' => $content,
            'created_at' => self::now(),
        ]);

        if ($id === false) {
            throw new RuntimeException('Internal note insert failed');
        }

        return [
            'id' => $id,
            'content' => $content,
        ];
    }

    public static function request_documents(
        int $applicationId,
        int $actorUserId,
        string $requestedItems
    ): array {
        $res = self::send_message_to_applicant(
            $applicationId,
            $actorUserId,
            'doc_request',
            $requestedItems
        );

        return [
            'message_id' => $res['message_id'],
            'new_status' => $res['new_status'],
        ];
    }

    public static function set_application_status(
        int $applicationId,
        int $actorUserId,
        string $status,
        ?string $reason = null
    ): array {
        $crud = self::makeCrud($actorUserId);

        $app = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $oldStatus = $app['status'];
        $status = (string) $status;

        self::validateStatusTransition($oldStatus, $status);

        $app = self::changeAppStatus($crud, $app, $status, $actorUserId, $reason);

        if (!empty($app['user_id'])) {
            RecruitmentMessageService::notify_candidate(
                (int) $app['user_id'],
                'status_updated',
                'Application status updated',
                "Your application status '{$status}' has been updated.",
                ['application_id' => $applicationId]
            );
        }

        return [
            'id' => $applicationId,
            'status' => $app['status'],
            'application' => $app,
        ];
    }

    public static function create_offer(
        int $applicationId,
        int $actorUserId,
        string $positionMode,
        ?string $positionCode,
        ?string $note
    ): array {
        $crud = self::makeCrud($actorUserId);

        $app = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        // Burada sadece status -> offer_sent yapıyoruz.
        // İleride applications tablosuna offer ile ilgili kolonlar eklersen,
        // burada onları da update edebilirsin.
        $app = self::changeAppStatus($crud, $app, self::S_APP_OFFER_SENT, $actorUserId, $note);

        if (!empty($app['user_id'])) {
            RecruitmentMessageService::notify_candidate(
                (int) $app['user_id'],
                'offer_sent',
                'Your application has an offer',
                'An offer has been sent for your application.',
                ['application_id' => $applicationId]
            );
        }

        return [
            'offer_id' => $applicationId, // ayrı offer tablosu yoksa application_id kullanılabilir
            'new_status' => $app['status'],
        ];
    }

    public static function accept_candidate_as_employee(
        int $applicationId,
        int $actorUserId
    ): array {
        $crud = self::makeCrud($actorUserId);

        $app = self::getOne($crud, self::T_APPS, ['id' => $applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        // Uygulama statusünü hired yap
        $app = self::changeAppStatus($crud, $app, self::S_APP_HIRED, $actorUserId, 'company_accepted');

        // TODO:
        // Burada company_users (veya benzeri) tablosuna insert yapılabilir.
        // Şema netleştiğinde:
        // $crud->create('company_users', [...]);

        return [
            'company_user_id' => 0, // şimdilik placeholder
            'application_status' => $app['status'],
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * CANDIDATE – JOBS & APPLICATIONS
     * -------------------------------------------------------------------------
     */

    public static function list_matching_job_posts(
        int $userId,
        int $page,
        int $perPage
    ): array {
        $crud = self::makeCrud($userId);

        // TODO: Kullanıcının ehliyet/sertifika/CV bilgilerine göre filtre eklenebilir.
        return self::paginate(
            $crud,
            self::T_JOB_POSTS,
            ['status' => self::S_JOB_PUBLISHED],
            $page,
            $perPage,
            ['created_at' => 'DESC']
        );
    }

    public static function list_public_job_posts(
        int $userId,
        int $page,
        int $perPage
    ): array {
        $crud = self::makeCrud($userId);

        return self::paginate(
            $crud,
            self::T_JOB_POSTS,
            ['status' => self::S_JOB_PUBLISHED],
            $page,
            $perPage,
            ['created_at' => 'DESC']
        );
    }

    public static function get_public_job_post(
        int $jobPostId,
        int $userId
    ): array {
        $crud = self::makeCrud($userId);

        $job = self::getOne($crud, self::T_JOB_POSTS, [
            'id' => $jobPostId,
            'status' => self::S_JOB_PUBLISHED,
        ]);

        if (!$job) {
            throw new RuntimeException('Job post not found or not published');
        }

        // 🔹 Şirket bilgisi (daha önce eklediysen aynı kalsın)
        $companyId = (int) ($job['company_id'] ?? 0);
        if ($companyId > 0) {
            $company = self::getOne($crud, self::T_COMPANIES, [
                'id' => $companyId,
            ]);
            if ($company) {
                $job['company_name'] = $company['name'] ?? null;
                $job['company_logo'] = $company['logo'] ?? null;
            }
        }

        // 🔹 Requirements JSON → certificate id listesi
        $reqJson = $job['requirements_json'];

        $reqIds = [];
        if (is_string($reqJson) && $reqJson !== '') {
            $dec = json_decode($reqJson, true);
            if (is_array($dec)) {
                foreach ($dec as $v) {
                    $id = (int) $v;
                    if ($id > 0) {
                        $reqIds[] = $id;
                    }
                }
                $reqIds = array_values(array_unique($reqIds));
            }
        }

        $requirements = [];
        if ($reqIds) {
            // certificates tablosundan id + name çek
            $rows = $crud->read(
                self::T_CERTIFICATES,
                ['id' => ['IN', $reqIds]],
                ['id', 'name', 'group_id', 'stcw_code', 'sort_order'],
                true
            ) ?: [];

            // id sırasını koruyalım
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }
            foreach ($reqIds as $id) {
                if (isset($byId[$id])) {
                    $requirements[] = $byId[$id];
                }
            }
        }

        // 🔹 İlanın içine ekle
        $job['requirements'] = $requirements;

        // Şirket içi gizli alanlar varsa ileride buradan filtreleyebilirsin.
        return ['job_post' => $job];
    }



    public static function submit_application(
        int $jobPostId,
        int $userId,
        string $coverMessage
    ): array {
        $crud = self::makeCrud($userId);

        $job = self::getOne($crud, self::T_JOB_POSTS, [
            'id' => $jobPostId,
            'status' => self::S_JOB_PUBLISHED,
        ]);
        if (!$job) {
            throw new RuntimeException('Job post not found or not open for application');
        }

        // Aynı ilana duplicate başvuruya engel
        $existing = self::getOne($crud, self::T_APPS, [
            'job_post_id' => $jobPostId,
            'user_id' => $userId,
        ]);
        if ($existing) {
            return $existing;
        }

        $insert = [
            'job_post_id' => $jobPostId,
            'company_id' => (int) $job['company_id'],
            'user_id' => $userId,
            'cover_letter' => $coverMessage, // DB kolon adı cover_letter varsayıldı
            'status' => self::S_APP_SUBMITTED,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ];

        $id = $crud->create(self::T_APPS, $insert);
        if ($id === false) {
            throw new RuntimeException('Application insert failed');
        }

        self::addStatusHistory(
            $crud,
            $id,
            self::S_APP_SUBMITTED,
            self::S_APP_SUBMITTED,
            $userId,
            null
        );

        // Şirket tarafına bildirim
        RecruitmentMessageService::notify_company_users(
            (int) $job['company_id'],
            'application_submitted',
            'New application received',
            'A new application has been submitted for your job post.',
            [
                'application_id' => $id,
                'job_post_id' => $jobPostId,
                'company_id' => (int) $job['company_id'],
                'target' => 'company_application_detail',
                'route' => '/company_application_detail',
                'route_args' => [
                    'company_id' => (int) $job['company_id'],
                    'application_id' => $id,
                ],
            ]
        );

        $app = self::getOne($crud, self::T_APPS, ['id' => $id]);
        return $app ?? ['id' => $id, 'status' => self::S_APP_SUBMITTED];
    }

    public static function withdraw_application(
        int $applicationId,
        int $userId
    ): array {
        $crud = self::makeCrud($userId);

        $app = self::getOne($crud, self::T_APPS, [
            'id' => $applicationId,
            'user_id' => $userId,
        ]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $oldStatus = $app['status'];

        // Sadece belirli statülerden çekilebilsin
        $allowedFrom = [
            self::S_APP_SUBMITTED,
            self::S_APP_UNDER_REVIEW,
            self::S_APP_IN_COMMUNICATION,
            self::S_APP_PENDING_DOCUMENTS,
            self::S_APP_OFFER_SENT,
        ];
        if (!in_array($oldStatus, $allowedFrom, true)) {
            throw new RuntimeException("Cannot withdraw from status: {$oldStatus}");
        }

        $app = self::changeAppStatus($crud, $app, self::S_APP_WITHDRAWN, $userId, 'candidate_withdrawn');

        // Şirkete bildirim
        if (!empty($app['company_id'])) {
            RecruitmentMessageService::notify_company_users(
                (int) $app['company_id'],
                'application_withdrawn',
                'Application withdrawn',
                'An application has been withdrawn by the candidate.',
                ['application_id' => $applicationId]
            );
        }

        return [
            'id' => $applicationId,
            'status' => $app['status'],
        ];
    }

    public static function list_my_applications(
        int $userId,
        int $page,
        int $perPage
    ): array {
        $crud = self::makeCrud($userId);

        return self::paginate(
            $crud,
            self::T_APPS,
            ['user_id' => $userId],
            $page,
            $perPage,
            ['created_at' => 'DESC']
        );
    }

    public static function get_my_application_detail(
        int $applicationId,
        int $userId
    ): array {
        $crud = self::makeCrud($userId);

        $app = self::getOne($crud, self::T_APPS, [
            'id' => $applicationId,
            'user_id' => $userId,
        ]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $messages = $crud->read(
            self::T_APP_MESSAGES,
            ['application_id' => $applicationId],
            ['*'],
            true,
            ['created_at' => 'ASC']
        ) ?: [];

        $history = $crud->read(
            self::T_APP_STATUS,
            ['application_id' => $applicationId],
            ['*'],
            true,
            ['created_at' => 'ASC']
        ) ?: [];

        // internal_notes BİLEREK dahil değil
        return [
            'application' => $app,
            'messages' => $messages,
            'status_history' => $history,
        ];
    }

    public static function reply_message(
        int $applicationId,
        int $userId,
        string $content
    ): array {
        $crud = self::makeCrud($userId);

        $app = self::getOne($crud, self::T_APPS, [
            'id' => $applicationId,
            'user_id' => $userId,
        ]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        $msgId = $crud->create(self::T_APP_MESSAGES, [
            'application_id' => $applicationId,
            'from_user_id' => $userId,
            'to_user_id' => null, // ilgili şirket kullanıcıları
            'direction' => 'candidate_to_company',
            'type' => 'text',
            'content' => $content,
            'created_at' => self::now(),
        ]);

        if ($msgId === false) {
            throw new RuntimeException('Reply message insert failed');
        }

        // Durumu iletişimde olarak işaretleyebiliriz
        $app = self::changeAppStatus($crud, $app, self::S_APP_IN_COMMUNICATION, $userId, 'candidate_replied');

        // Şirkete bildirim
        if (!empty($app['company_id'])) {
            RecruitmentMessageService::notify_company_users(
                (int) $app['company_id'],
                'candidate_replied',
                'Candidate replied to application',
                'A candidate has replied to an application.',
                ['application_id' => $applicationId, 'message_id' => $msgId]
            );
        }

        return [
            'id' => $msgId,
            'content' => $content,
        ];
    }

    public static function accept_offer(
        int $applicationId,
        int $userId
    ): array {
        $crud = self::makeCrud($userId);

        $app = self::getOne($crud, self::T_APPS, [
            'id' => $applicationId,
            'user_id' => $userId,
        ]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        if ($app['status'] !== self::S_APP_OFFER_SENT) {
            throw new RuntimeException('Offer is not in offer_sent status');
        }

        $app = self::changeAppStatus($crud, $app, self::S_APP_HIRED, $userId, 'candidate_accepted_offer');

        // Şirkete bildirim
        if (!empty($app['company_id'])) {
            RecruitmentMessageService::notify_company_users(
                (int) $app['company_id'],
                'offer_accepted',
                'Accepted job offer',
                'A candidate has accepted a job offer.',
                ['application_id' => $applicationId]
            );
        }

        return [
            'application_id' => $applicationId,
            'status' => $app['status'],
        ];
    }

    public static function decline_offer(
        int $applicationId,
        int $userId
    ): array {
        $crud = self::makeCrud($userId);

        $app = self::getOne($crud, self::T_APPS, [
            'id' => $applicationId,
            'user_id' => $userId,
        ]);
        if (!$app) {
            throw new RuntimeException('Application not found');
        }

        if ($app['status'] !== self::S_APP_OFFER_SENT) {
            throw new RuntimeException('Offer is not in offer_sent status');
        }

        $app = self::changeAppStatus($crud, $app, self::S_APP_OFFER_DECLINED, $userId, 'candidate_declined_offer');

        // Şirkete bildirim
        RecruitmentMessageService::notify_company_users(
            (int) $app['company_id'],
            'offer_declined',
            'Offer declined',
            'A candidate has declined a job offer.',
            [
                'application_id' => $applicationId,
                'company_id' => (int) $app['company_id'],
                'target' => 'company_application_detail',
                'route' => '/company_application_detail',
                'route_args' => [
                    'company_id' => (int) $app['company_id'],
                    'application_id' => $applicationId,
                ],
            ]
        );

        return [
            'application_id' => $applicationId,
            'status' => $app['status'],
        ];
    }
}
