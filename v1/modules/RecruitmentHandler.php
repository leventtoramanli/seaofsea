<?php
// v1/modules/RecruitmentHandler.php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/log.php';
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

            // Domain service'e delege
            $res = RecruitmentDomainService::list_company_applications(
                $companyId,
                $userId,
                $page,
                $perPage,
                $status
            );

            return self::ok($res, 'Company applications');
        } catch (\Throwable $e) {
            return self::handleException('list_company_applications', $e, $params);
        }
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

            return self::ok($res, 'Application detail');
        } catch (\Throwable $e) {
            return self::handleException('get_application_detail', $e, $params);
        }
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
