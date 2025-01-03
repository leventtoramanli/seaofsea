<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/DatabaseHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';

class ReportHandler {
    private $crud;

    public function __construct() {
        // CRUDHandler nesnesi oluştur
        $this->crud = new CRUDHandler();
    }

    // Kullanıcı bazlı rapor
    public function getUserReport($userId) {
        return $this->crud->read(
            table: 'users',
            conditions: ['id' => $userId],
            columns: ['id', 'name', 'email', 'created_at'],
            fetchAll: false
        );
    }

    // Günlük işlem raporu
    public function getDailyActivityReport($date) {
        return $this->crud->read(
            table: 'logs',
            conditions: [
                ['date' => ['operator' => '=', 'value' => $date]]
            ],
            columns: ['action', 'COUNT(*) as total'],
            additionaly: ['groupBy' => ['action'], 'orderBy' => ['total' => 'desc']],
            fetchAll: true
        );
    }

    // Genel sistem istatistikleri
    public function getSystemStats() {
        $totalUsers = $this->crud->count('users');
        $verifiedUsers = $this->crud->count('users', ['is_verified' => 1]);
        $totalLogs = $this->crud->count('logs');

        return [
            'total_users' => $totalUsers,
            'verified_users' => $verifiedUsers,
            'total_logs' => $totalLogs
        ];
    }
}
