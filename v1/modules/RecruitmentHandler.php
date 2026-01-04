<?php
// v1/modules/RecruitmentHandler.php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/log.php';
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/permissionGate.php';
require_once __DIR__ . '/RecruitmentDomainService.php';

class RecruitmentHandler
{
    /*
     * --------------------------------------------------------------
     * Ortak helperlar
     * --------------------------------------------------------------
     */

    private static function ok(mixed $data, string $message = 'OK', int $code = 200): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ];
    }

    private static function fail(string $message, int $code = 400, mixed $data = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ];
    }

    private static function handleException(string $context, \Throwable $e, array $params): array
    {
        // Validation hatası
        if ($e instanceof \InvalidArgumentException) {
            return self::fail($e->getMessage(), 422);
        }

        // İş kuralı / not found vs.
        if ($e instanceof \RuntimeException) {
            $msg = $e->getMessage();
            $msgL = strtolower($msg);
            $code = 400;

            if (strpos($msgL, 'not found') !== false) {
                $code = 404;
            }

            Logger::error("RecruitmentHandler.{$context} runtime error", [
                'message' => $msg,
                'params' => $params,
            ]);

            return self::fail($msg, $code);
        }

        // Beklenmeyen hata
        Logger::error("RecruitmentHandler.{$context} fatal error", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'params' => $params,
        ]);

        return self::fail('Internal server error', 500);
    }

    private static function getPage(array $params): int
    {
        $page = (int) ($params['page'] ?? 1);
        return $page > 0 ? $page : 1;
    }

    private static function getPerPage(array $params, int $default = 20, int $max = 100): int
    {
        $perPage = (int) ($params['per_page'] ?? $default);
        if ($perPage <= 0)
            $perPage = $default;
        if ($perPage > $max)
            $perPage = $max;
        return $perPage;
    }

    /*
     * --------------------------------------------------------------
     * COMPANY TARAFI – JOB POSTS
     * --------------------------------------------------------------
     */

    public static function create_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $companyId = (int) ($params['company_id'] ?? 0);
            if ($companyId <= 0) {
                return self::fail('company_id is required', 422);
            }

            $job = RecruitmentDomainService::create_job_post($companyId, $userId, $params);

            return self::ok(['job_post' => $job], 'Job post created');
        } catch (\Throwable $e) {
            return self::handleException('create_job_post', $e, $params);
        }
    }

    public static function update_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $job = RecruitmentDomainService::update_job_post($jobPostId, $userId, $params);

            return self::ok(['job_post' => $job], 'Job post updated');
        } catch (\Throwable $e) {
            return self::handleException('update_job_post', $e, $params);
        }
    }

    public static function publish_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $job = RecruitmentDomainService::publish_job_post($jobPostId, $userId);

            return self::ok(['job_post' => $job], 'Job post published');
        } catch (\Throwable $e) {
            return self::handleException('publish_job_post', $e, $params);
        }
    }

    public static function close_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $job = RecruitmentDomainService::close_job_post($jobPostId, $userId);

            return self::ok(['job_post' => $job], 'Job post closed');
        } catch (\Throwable $e) {
            return self::handleException('close_job_post', $e, $params);
        }
    }

    public static function archive_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $job = RecruitmentDomainService::archive_job_post($jobPostId, $userId);

            return self::ok(['job_post' => $job], 'Job post archived');
        } catch (\Throwable $e) {
            return self::handleException('archive_job_post', $e, $params);
        }
    }

    public static function delete_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }
            $ok = RecruitmentDomainService::delete_job_post($jobPostId, $userId);
            if (!$ok) {
                return self::fail('Job post could not be deleted (maybe has applications)', 400);
            }

            return self::ok(['deleted' => true], 'Job post deleted');
        } catch (\Throwable $e) {
            return self::handleException('delete_job_post', $e, $params);
        }
    }

    public static function list_company_applications(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];
            $companyId = (int) ($params['company_id'] ?? 0);
            if ($companyId <= 0) {
                return self::fail('company_id is required', 422);
            }

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);
            $status = isset($params['status']) && $params['status'] !== ''
                ? (string) $params['status'] : null;

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                $jobPostId = null;
            }

            $q = isset($params['q']) ? trim((string) $params['q']) : null;
            if ($q === '') {
                $q = null;
            }

            // Domain service'e delege
            $res = RecruitmentDomainService::list_company_applications(
                $companyId,
                $userId,
                $page,
                $perPage,
                $status,
                $jobPostId,
                $q
            );

            return self::ok($res, 'Company applications');
        } catch (\Throwable $e) {
            return self::handleException('list_company_applications', $e, $params);
        }
    }

    /**
     * Flutter tarafında kullanılan alias.
     * CompanyApplicationsPage -> module: recruitment, action: app_list_for_company
     */
    public static function app_list_for_company(array $params): array
    {
        // Şimdilik aynı davranış: list_company_applications
        return self::list_company_applications($params);
    }

    public static function list_company_job_posts(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $companyId = (int) ($params['company_id'] ?? 0);
            if ($companyId <= 0) {
                return self::fail('company_id is required', 422);
            }

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);
            $status = isset($params['status']) && $params['status'] !== ''
                ? (string) $params['status']
                : null;

            $res = RecruitmentDomainService::list_company_job_posts(
                $companyId,
                $userId,
                $page,
                $perPage,
                $status
            );

            return self::ok($res, 'Company job posts');
        } catch (\Throwable $e) {
            return self::handleException('list_company_job_posts', $e, $params);
        }
    }

    public static function get_job_post_detail(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $res = RecruitmentDomainService::get_job_post_detail($jobPostId, $userId);

            return self::ok($res, 'Job post detail');
        } catch (\Throwable $e) {
            return self::handleException('get_job_post_detail', $e, $params);
        }
    }

    public static function company_app_stats(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $companyId = (int) ($params['company_id'] ?? 0);
            if ($companyId <= 0) {
                return self::fail('company_id is required', 422);
            }

            $res = RecruitmentDomainService::get_company_app_stats(
                $companyId,
                $userId
            );

            return self::ok($res, 'Company application stats');
        } catch (\Throwable $e) {
            return self::handleException('company_app_stats', $e, $params);
        }
    }

    /*
     * --------------------------------------------------------------
     * COMPANY TARAFI – APPLICATIONS
     * --------------------------------------------------------------
     */

    public static function list_job_applications(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);
            $status = isset($params['status']) && $params['status'] !== ''
                ? (string) $params['status']
                : null;

            $res = RecruitmentDomainService::list_job_applications(
                $jobPostId,
                $userId,
                $page,
                $perPage,
                $status
            );

            return self::ok($res, 'Job applications');
        } catch (\Throwable $e) {
            return self::handleException('list_job_applications', $e, $params);
        }
    }

    public static function get_application_detail(array $params): array
    {
        Logger::info('Recruitment.get_application_detail called', [
            'params' => $params,
        ]);
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $auto = !isset($params['auto_mark_under_review']) ||
                (bool) $params['auto_mark_under_review'];

            $res = RecruitmentDomainService::get_application_detail(
                $appId,
                $userId,
                $auto
            );
            $detail = $res;
            Logger::info('Recruitment.get_application_detail domain result', [
                'keys' => is_array($detail) ? array_keys($detail) : gettype($detail),
                'counts' => [
                    'messages' => (is_array($detail['messages'] ?? null) ? count($detail['messages']) : -1),
                    'internal_notes' => (is_array($detail['internal_notes'] ?? null) ? count($detail['internal_notes']) : -1),
                    'status_history' => (is_array($detail['status_history'] ?? null) ? count($detail['status_history']) : -1),
                ],
                // Çok uzamasın diye sadece ilk item’ı örnek basıyoruz
                'sample' => [
                    'message0' => (is_array($detail['messages'] ?? null) && !empty($detail['messages']))
                        ? $detail['messages'][0]
                        : null,
                    'note0' => (is_array($detail['internal_notes'] ?? null) && !empty($detail['internal_notes']))
                        ? $detail['internal_notes'][0]
                        : null,
                ],
            ]);


            return self::ok($res, 'Application detail');
        } catch (\Throwable $e) {
            return self::handleException('get_application_detail', $e, $params);
        }
    }

    public static function status_config(array $params): array
    {
        try {
            Auth::requireAuth();
            return self::ok([
                'statuses' => [
                    'submitted',
                    'under_review',
                    'in_communication',
                    'pending_documents',
                    'offer_sent',
                    'offer_declined',
                    'hired',
                    'rejected',
                    'withdrawn',
                ],
                'filters' => ['active', 'terminal'],
            ], 'Status config');
        } catch (\Throwable $e) {
            return self::handleException('status_config', $e, $params);
        }
    }

    /**
     * Legacy endpoint for CompanyApplicationDetailPage
     * action: app_timeline
     */
    public static function app_timeline(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) ($auth['user_id'] ?? 0);

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $limit = (int) ($params['limit'] ?? 30);
            if ($limit <= 0)
                $limit = 30;
            if ($limit > 100)
                $limit = 100;

            $beforeTs = isset($params['before_ts']) ? trim((string) $params['before_ts']) : null;
            if ($beforeTs === '')
                $beforeTs = null;

            Logger::info('Recruitment.app_timeline called', [
                'user_id' => $userId,
                'application_id' => $appId,
                'limit' => $limit,
                'before_ts' => $beforeTs,
            ]);

            // Timeline sadece okumadır; status auto-change yapmayalım:
            $detail = RecruitmentDomainService::get_application_detail($appId, $userId, false);

            $app = (isset($detail['application']) && is_array($detail['application'])) ? $detail['application'] : [];
            $companyId = (int) ($app['company_id'] ?? 0);
            $candidateUserId = (int) ($app['user_id'] ?? 0);

            // Viewer applicant mi?
            $isApplicantViewer = ($candidateUserId > 0 && $candidateUserId === $userId);

            // Company setting: hide staff names to applicant
            $hideStaffNames = true; // default 1 (senin istediğin)
            if ($companyId > 0) {
                $crud = new Crud($userId, true);
                $row = $crud->read('companies', ['id' => $companyId], ['id', 'hide_staff_names_to_applicant'], false);
                if (is_array($row) && isset($row['hide_staff_names_to_applicant'])) {
                    $hideStaffNames = ((int) $row['hide_staff_names_to_applicant']) === 1;
                }
            }

            Logger::info('Recruitment.app_timeline viewer context', [
                'company_id' => $companyId,
                'candidate_user_id' => $candidateUserId,
                'is_applicant_viewer' => $isApplicantViewer,
                'hide_staff_names_to_applicant' => $hideStaffNames ? 1 : 0,
            ]);

            $history = (isset($detail['status_history']) && is_array($detail['status_history'])) ? $detail['status_history'] : [];
            $notes = (isset($detail['internal_notes']) && is_array($detail['internal_notes'])) ? $detail['internal_notes'] : [];
            $msgs = (isset($detail['messages']) && is_array($detail['messages'])) ? $detail['messages'] : [];

            Logger::info('Recruitment.app_timeline domain counts', [
                'status_history' => count($history),
                'internal_notes' => count($notes),
                'messages' => count($msgs),
            ]);

            // Kullanıcı map'i (id => ['name'=>..., 'avatar'=>...])
            $userIds = [];

            foreach ($history as $h) {
                if (isset($h['changed_by']))
                    $userIds[] = (int) $h['changed_by'];
            }
            foreach ($notes as $n) {
                if (isset($n['user_id']))
                    $userIds[] = (int) $n['user_id'];
            }
            foreach ($msgs as $m) {
                if (isset($m['from_user_id']))
                    $userIds[] = (int) $m['from_user_id'];
            }

            $userMap = self::loadUsersMap(array_values(array_unique(array_filter($userIds))));

            // Helper: actor maskeleme (applicant viewer + hideStaffNames)
            $maskActorIfNeeded = function (array $actor, int $actorId) use ($isApplicantViewer, $hideStaffNames, $candidateUserId) {
                if (!$isApplicantViewer)
                    return $actor;
                if (!$hideStaffNames)
                    return $actor;

                // Applicant kendi kendini görebilir (kendi adı kalabilir)
                if ($candidateUserId > 0 && $actorId === $candidateUserId) {
                    return $actor;
                }

                // Diğer herkes "Company"
                return [
                    'id' => $actorId,
                    'name' => 'Company',
                    'avatar' => null,
                ];
            };

            $items = [];

            // 1) status history -> type: status
            foreach ($history as $h) {
                $ts = (string) ($h['created_at'] ?? '');
                if ($ts === '')
                    continue;

                $actorId = (int) ($h['changed_by'] ?? 0);
                $actor = self::actorFromMap($userMap, $actorId);
                $actor = $maskActorIfNeeded($actor, $actorId);

                $noteText = (string) ($h['note'] ?? '');

                $items[] = [
                    'id' => (string) ($h['id'] ?? ('status:' . $ts . ':' . $actorId)),
                    'ts' => self::toIso($ts),
                    'type' => 'status',
                    'actor' => $actor,
                    'user_id' => $actorId,
                    'user_full_name' => $actor['name'] ?? null,
                    'data' => [
                        'from' => (string) ($h['old_status'] ?? ''),
                        'to' => (string) ($h['new_status'] ?? ''),
                        'note' => $noteText ?: null,
                        'content' => $noteText, // UI eksik kalmasın
                    ],
                ];
            }

            // 2) internal notes -> type: internal_note (company)
            // Applicant viewer ise internal_note ASLA dönmeyelim
            if (!$isApplicantViewer) {
                foreach ($notes as $n) {
                    $ts = (string) ($n['created_at'] ?? '');
                    if ($ts === '')
                        continue;

                    $actorId = (int) ($n['user_id'] ?? 0);
                    $actor = self::actorFromMap($userMap, $actorId);

                    $content = (string) ($n['content'] ?? $n['note'] ?? '');

                    $items[] = [
                        'id' => (string) ($n['id'] ?? ('internal_note:' . $ts . ':' . $actorId)),
                        'ts' => self::toIso($ts),
                        'type' => 'internal_note',
                        'actor' => $actor,
                        'user_id' => $actorId,
                        'user_full_name' => $actor['name'] ?? null,
                        'data' => [
                            'visibility' => 'company',
                            'published' => false,
                            'content' => $content,
                        ],
                    ];
                }
            }

            // 3) messages -> doc_request or message
            foreach ($msgs as $m) {
                $ts = (string) ($m['created_at'] ?? '');
                if ($ts === '')
                    continue;

                $actorId = (int) ($m['from_user_id'] ?? 0);
                $actor = self::actorFromMap($userMap, $actorId);

                $mType = (string) ($m['type'] ?? 'text');
                $content = (string) ($m['content'] ?? '');
                $direction = (string) ($m['direction'] ?? '');

                // doc_request ise ayrı
                if ($mType === 'doc_request') {
                    $actor = $maskActorIfNeeded($actor, $actorId); // doc request staff ise maskelenebilir
                    $items[] = [
                        'id' => (string) ($m['id'] ?? ('doc_request:' . $ts . ':' . $actorId)),
                        'ts' => self::toIso($ts),
                        'type' => 'doc_request',
                        'actor' => $actor,
                        'user_id' => $actorId,
                        'user_full_name' => $actor['name'] ?? null,
                        'data' => [
                            'status' => (string) ($m['status'] ?? 'open'),
                            'content' => $content,
                        ],
                    ];
                    continue;
                }

                // Normal message
                // Applicant viewer + hideStaffNames ise company_to_candidate mesajlarında staff ismini maskele
                if ($isApplicantViewer && $hideStaffNames && $direction === 'company_to_candidate') {
                    $actor = $maskActorIfNeeded($actor, $actorId);
                }

                $items[] = [
                    'id' => (string) ($m['id'] ?? ('message:' . $ts . ':' . $actorId)),
                    'ts' => self::toIso($ts),
                    'type' => 'message',
                    'actor' => $actor,
                    'user_id' => $actorId,
                    'user_full_name' => $actor['name'] ?? null,
                    'data' => [
                        'visibility' => 'applicant',
                        'published' => true,
                        'direction' => $direction,
                        'message_type' => $mType,
                        'content' => $content,
                    ],
                ];
            }

            // newest -> oldest
            usort($items, function ($a, $b) {
                return strcmp((string) $b['ts'], (string) $a['ts']);
            });

            // before_ts filtresi (exclusive)
            if ($beforeTs) {
                $beforeIso = self::toIso($beforeTs);
                $items = array_values(array_filter($items, function ($it) use ($beforeIso) {
                    return strcmp((string) $it['ts'], (string) $beforeIso) < 0;
                }));
            }

            // limit
            if (count($items) > $limit) {
                $items = array_slice($items, 0, $limit);
            }

            Logger::info('Recruitment.app_timeline result', [
                'items' => count($items),
                'sample0' => $items[0] ?? null,
            ]);

            return self::ok(['items' => $items], 'Timeline');
        } catch (\Throwable $e) {
            return self::handleException('app_timeline', $e, $params);
        }
    }

    /**
     * Legacy endpoint for CompanyApplicationDetailPage
     * action: app_notes (internal notes list)
     */
    public static function app_notes(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) ($auth['user_id'] ?? 0);

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            // sadece okumadır; auto-change yok
            $detail = RecruitmentDomainService::get_application_detail($appId, $userId, false);
            $notes = (isset($detail['internal_notes']) && is_array($detail['internal_notes'])) ? $detail['internal_notes'] : [];

            $ids = [];
            foreach ($notes as $n) {
                if (isset($n['user_id']))
                    $ids[] = (int) $n['user_id'];
            }
            $userMap = self::loadUsersMap(array_values(array_unique(array_filter($ids))));

            $items = [];
            foreach ($notes as $n) {
                $ts = (string) ($n['created_at'] ?? '');
                $actorId = (int) ($n['user_id'] ?? 0);
                $actor = self::actorFromMap($userMap, $actorId);

                $items[] = [
                    'id' => (string) ($n['id'] ?? null),
                    'created_at' => self::toIso($ts),
                    'user_id' => $actorId,
                    'user_full_name' => $actor['name'] ?? null,
                    'content' => (string) ($n['content'] ?? ''),
                ];
            }

            // newest -> oldest (UI genelde böyle seviyor)
            usort($items, function ($a, $b) {
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });

            return self::ok(['items' => $items], 'Notes');
        } catch (\Throwable $e) {
            return self::handleException('app_notes', $e, $params);
        }
    }

    /**
     * Legacy endpoint for CompanyApplicationDetailPage
     * action: reviewer_positions
     */
    public static function reviewer_positions(array $params): array
    {
        try {
            Auth::requireAuth();
            return self::ok(['items' => []], 'Reviewer positions');
        } catch (\Throwable $e) {
            return self::handleException('reviewer_positions', $e, $params);
        }
    }

    /**
     * Legacy endpoint for CompanyApplicationDetailPage
     * action: reviewer_candidates_by_position
     */
    public static function reviewer_candidates_by_position(array $params): array
    {
        try {
            Auth::requireAuth();
            return self::ok(['items' => [], 'total' => 0], 'Reviewer candidates');
        } catch (\Throwable $e) {
            return self::handleException('reviewer_candidates_by_position', $e, $params);
        }
    }

    /**
     * Legacy endpoint for CompanyApplicationDetailPage
     * action: app_assign_reviewer
     *
     * Şimdilik reviewer modülü DB'de olmadığı için:
     * - işlemi "internal note" olarak kaydediyoruz (iz kalsın)
     */
    public static function app_assign_reviewer(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) ($auth['user_id'] ?? 0);

            $appId = (int) ($params['application_id'] ?? 0);
            $reviewerId = (int) ($params['reviewer_id'] ?? 0);

            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }
            if ($reviewerId <= 0) {
                return self::fail('reviewer_id is required', 422);
            }

            $msg = "Reviewer assignment requested: reviewer_id={$reviewerId}";
            RecruitmentDomainService::add_internal_note($appId, $userId, $msg);

            return self::ok(['assigned' => true], 'Reviewer assignment logged');
        } catch (\Throwable $e) {
            return self::handleException('app_assign_reviewer', $e, $params);
        }
    }

    // -------------------------
    // helpers (class internal)
    // -------------------------

    private static function toIso(string $dt): string
    {
        $dt = trim($dt);
        if ($dt === '')
            return '';
        try {
            $d = new DateTime($dt);
            return $d->format('c');
        } catch (\Throwable $e) {
            return $dt;
        }
    }

    private static function actorFromMap(array $userMap, int $userId): array
    {
        if ($userId <= 0) {
            return ['id' => 0, 'name' => 'System', 'avatar' => null];
        }
        $u = $userMap[$userId] ?? null;
        return [
            'id' => $userId,
            'name' => $u['name'] ?? ('User #' . $userId),
            'avatar' => $u['avatar'] ?? null,
        ];
    }

    private static function loadUsersMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids))
            return [];

        // permission guard kapalı: sadece internal enrichment
        $crud = new Crud(null, false);

        $rows = $crud->read('users', ['id' => ['IN', $ids]], ['id', 'name', 'surname', 'user_image'], true) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0)
                continue;
            $full = trim(((string) ($r['name'] ?? '')) . ' ' . ((string) ($r['surname'] ?? '')));
            $map[$id] = [
                'name' => ($full !== '') ? $full : ('User #' . $id),
                'avatar' => ($r['user_image'] ?? null),
            ];
        }
        return $map;
    }

    public static function send_message_to_applicant(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $messageType = (string) ($params['message_type'] ?? 'text');
            $content = trim((string) ($params['content'] ?? ''));

            if ($content === '') {
                return self::fail('content is required', 422);
            }

            $res = RecruitmentDomainService::send_message_to_applicant(
                $appId,
                $userId,
                $messageType,
                $content
            );

            return self::ok($res, 'Message sent to applicant');
        } catch (\Throwable $e) {
            return self::handleException('send_message_to_applicant', $e, $params);
        }
    }

    public static function add_internal_note(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $content = trim((string) ($params['content'] ?? ''));
            if ($content === '') {
                return self::fail('content is required', 422);
            }

            $res = RecruitmentDomainService::add_internal_note(
                $appId,
                $userId,
                $content
            );

            return self::ok($res, 'Internal note added');
        } catch (\Throwable $e) {
            return self::handleException('add_internal_note', $e, $params);
        }
    }

    public static function request_documents(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $requestedItems = trim((string) ($params['requested_items'] ?? ''));
            if ($requestedItems === '') {
                return self::fail('requested_items is required', 422);
            }

            $res = RecruitmentDomainService::request_documents(
                $appId,
                $userId,
                $requestedItems
            );

            return self::ok($res, 'Documents requested');
        } catch (\Throwable $e) {
            return self::handleException('request_documents', $e, $params);
        }
    }

    public static function set_application_status(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $status = (string) ($params['status'] ?? '');
            $status = self::normalizeStatusForServer($status);
            if ($status === '') {
                return self::fail('status is required', 422);
            }

            $reason = isset($params['reason']) ? (string) $params['reason'] : null;

            $res = RecruitmentDomainService::set_application_status(
                $appId,
                $userId,
                $status,
                $reason
            );

            return self::ok($res, 'Application status updated');
        } catch (\Throwable $e) {
            return self::handleException('set_application_status', $e, $params);
        }
    }

    private static function normalizeStatusForServer(string $status): string
    {
        $s = trim($status);
        if ($s === 'shortlisted' || $s === 'interview')
            return 'in_communication';
        if ($s === 'offered')
            return 'offer_sent';
        return $s;
    }


    public static function create_offer(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $positionMode = (string) ($params['position_mode'] ?? 'as_post'); // as_post | custom
            $positionCode = isset($params['position_code']) ? (string) $params['position_code'] : null;
            $note = isset($params['note']) ? (string) $params['note'] : null;

            $res = RecruitmentDomainService::create_offer(
                $appId,
                $userId,
                $positionMode,
                $positionCode,
                $note
            );

            return self::ok($res, 'Offer created');
        } catch (\Throwable $e) {
            return self::handleException('create_offer', $e, $params);
        }
    }

    public static function accept_candidate_as_employee(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $res = RecruitmentDomainService::accept_candidate_as_employee(
                $appId,
                $userId
            );

            return self::ok($res, 'Candidate accepted as employee');
        } catch (\Throwable $e) {
            return self::handleException('accept_candidate_as_employee', $e, $params);
        }
    }

    /*
     * --------------------------------------------------------------
     * CANDIDATE TARAFI – JOBS & APPLICATIONS
     * --------------------------------------------------------------
     */

    public static function list_matching_job_posts(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);

            $res = RecruitmentDomainService::list_matching_job_posts(
                $userId,
                $page,
                $perPage
            );

            return self::ok($res, 'Matching job posts');
        } catch (\Throwable $e) {
            return self::handleException('list_matching_job_posts', $e, $params);
        }
    }

    public static function list_public_job_posts(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);

            $res = RecruitmentDomainService::list_public_job_posts(
                $userId,
                $page,
                $perPage
            );

            return self::ok($res, 'Public job posts');
        } catch (\Throwable $e) {
            return self::handleException('list_public_job_posts', $e, $params);
        }
    }

    public static function get_public_job_post(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $res = RecruitmentDomainService::get_public_job_post(
                $jobPostId,
                $userId
            );

            return self::ok($res, 'Public job post detail');
        } catch (\Throwable $e) {
            return self::handleException('get_public_job_post', $e, $params);
        }
    }

    public static function submit_application(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $jobPostId = (int) ($params['job_post_id'] ?? 0);
            if ($jobPostId <= 0) {
                return self::fail('job_post_id is required', 422);
            }

            $coverMessage = trim((string) ($params['cover_message'] ?? ''));
            // Boş mesajı da izin verebilirsin, şimdilik optional – kontrolü kaldırmak istersen:
            // if ($coverMessage === '') { ... }

            $res = RecruitmentDomainService::submit_application(
                $jobPostId,
                $userId,
                $coverMessage
            );

            return self::ok($res, 'Application submitted');
        } catch (\Throwable $e) {
            return self::handleException('submit_application', $e, $params);
        }
    }

    public static function withdraw_application(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $res = RecruitmentDomainService::withdraw_application(
                $appId,
                $userId
            );

            return self::ok($res, 'Application withdrawn');
        } catch (\Throwable $e) {
            return self::handleException('withdraw_application', $e, $params);
        }
    }

    public static function list_my_applications(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);

            $res = RecruitmentDomainService::list_my_applications(
                $userId,
                $page,
                $perPage
            );

            return self::ok($res, 'My applications');
        } catch (\Throwable $e) {
            return self::handleException('list_my_applications', $e, $params);
        }
    }

    public static function get_my_application_detail(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $res = RecruitmentDomainService::get_my_application_detail(
                $appId,
                $userId
            );

            return self::ok($res, 'My application detail');
        } catch (\Throwable $e) {
            return self::handleException('get_my_application_detail', $e, $params);
        }
    }

    public static function reply_message(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $content = trim((string) ($params['content'] ?? ''));
            if ($content === '') {
                return self::fail('content is required', 422);
            }

            $res = RecruitmentDomainService::reply_message(
                $appId,
                $userId,
                $content
            );

            return self::ok($res, 'Reply sent');
        } catch (\Throwable $e) {
            return self::handleException('reply_message', $e, $params);
        }
    }

    public static function accept_offer(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $res = RecruitmentDomainService::accept_offer(
                $appId,
                $userId
            );

            return self::ok($res, 'Offer accepted');
        } catch (\Throwable $e) {
            return self::handleException('accept_offer', $e, $params);
        }
    }

    public static function decline_offer(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $appId = (int) ($params['application_id'] ?? 0);
            if ($appId <= 0) {
                return self::fail('application_id is required', 422);
            }

            $res = RecruitmentDomainService::decline_offer(
                $appId,
                $userId
            );

            return self::ok($res, 'Offer declined');
        } catch (\Throwable $e) {
            return self::handleException('decline_offer', $e, $params);
        }
    }
}
