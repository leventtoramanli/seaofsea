<?php
// v1/modules/RecruitmentMessageService.php

require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/log.php';

class RecruitmentMessageService
{
    private const T_NOTIFICATIONS = 'notifications';
    private const T_COMPANY_USERS = 'company_users';

    /**
     * Sistem kaynaklı insertlerde permission guard'ı kapatmak için
     * userId = null, guard = false kullanıyoruz.
     */
    private static function makeCrudSystem(): Crud
    {
        // Crud::__construct(?int $userId = null, bool $permissionGuard = true)
        // Burada permissionGuard=false → AuthService::can devre dışı (sistem yazıyor).
        return new Crud(null, false);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Tek bir kullanıcıya notification kaydı
     */
    private static function logNotification(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $meta = []
    ): int {
        $crud = self::makeCrudSystem();

        $insert = [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'is_read' => 0,
            'created_at' => self::now(),
        ];

        $id = $crud->create(self::T_NOTIFICATIONS, $insert);
        if ($id === false) {
            Logger::error('Notification insert failed', [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
            ]);
            return 0;
        }

        return $id;
    }

    /**
     * İlgili adaya (candidate) notification gönderir.
     * DomainService tarafından kullanılıyor.
     */
    public static function notify_candidate(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $meta = []
    ): int {
        if ($userId <= 0) {
            Logger::error('notify_candidate: invalid userId', [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'meta' => $meta,
            ]);
            return 0;
        }

        if (isset($meta['application_id'])) {
            $appId = (int) $meta['application_id'];

            if (!isset($meta['target'])) {
                $meta['target'] = 'candidate_application_detail';
            }

            if (!isset($meta['route'])) {
                // Flutter tarafında MyApplicationsPage.routeName
                // ama meta tarafında sade string tutmak yeterli
                $meta['route'] = '/my_applications';
            }

            if (!isset($meta['route_args']) || !is_array($meta['route_args'])) {
                $meta['route_args'] = [
                    'application_id' => $appId,
                ];
            }
        }
        return self::logNotification($userId, $type, $title, $body, $meta);
    }
    /**
     * Şirketin tüm yetkili kullanıcılarına notification gönderir.
     * Ör: yeni başvuru, adayın geri çekmesi, adayın cevap yazması vs.
     */
    public static function notify_company_users(
        int $companyId,
        string $type,
        string $title,
        string $body,
        array $meta = []
    ): void {
        if ($companyId <= 0) {
            Logger::error('notify_company_users called with invalid companyId', [
                'company_id' => $companyId,
                'type' => $type,
            ]);
            return;
        }

        $crud = self::makeCrudSystem();

        // Burada company_users tablosundan aktif kullanıcıları çekiyoruz.
        // Şema örneği varsayımı:
        // company_users(id, company_id, user_id, role, status, ...)
        $rows = $crud->read(
            self::T_COMPANY_USERS,
            [
                'company_id' => $companyId,
                // İstersen status filtresi ekleyebilirsin:
                // 'status' => 'active',
            ],
            ['user_id'],
            true
        );

        if (!is_array($rows) || empty($rows)) {
            Logger::error('notify_company_users: no company users found', [
                'company_id' => $companyId,
                'type' => $type,
            ]);
            return;
        }

        $userIds = [];
        foreach ($rows as $r) {
            $uid = (int) ($r['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true; // uniq
            }
        }

        foreach (array_keys($userIds) as $uid) {
            self::logNotification($uid, $type, $title, $body, $meta);
        }
    }
}
