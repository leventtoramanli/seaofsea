<?php
require_once __DIR__ . '/../handlers/CRUDHandlers.php';
use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
class CompanyHandler {
    private $crudHandler;
    private static $logger;
    private static $loggerInfo;
    private $userId;
    public function __construct() {
        $this->crudHandler = new CRUDHandler();
        $this->userId = getUserIdFromToken();
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }
        if (!self::$loggerInfo) {
            self::$loggerInfo = getLoggerInfo(); // Merkezi logger
        }
    }
    private function buildResponse(bool $success, string $message, array $data = [], bool $showMessage = false, array $errors = []): array {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'showMessage' => $showMessage,
        ];
    }
    public function createCompany(array $data): array
    {
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
            'showMessage' => true
        ];

        $userId = $this->userId;

        if (!$userId || empty($data['name'])) {
            return $this->buildResponse(false, 'Company name is required.');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->buildResponse(false, 'Invalid email address.');
        }

        $existing = $this->crudHandler->read('companies', ['email' => $data['email']], ['id'], false);

        if (!empty($existing)) {
            return $this->buildResponse(false, 'A company with this email already exists.');
        }

        $companyId = $this->crudHandler->create('companies', [
            'name' => $data['name'],
            'email' => $data['email'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($companyId) {
            return $this->buildResponse(true, 'Company created successfully.', ['company_id' => (int)$companyId]);
        }
        return $this->buildResponse(false, 'Failed to create company.');
    }
    public function createUserCompany(array $data): array
    {

        $userId = $this->userId;

        if (!$userId || empty($data['company_id']) || empty($data['role']) || empty($data['rank'])) {
            return $this->buildResponse(false, 'Company ID, role and rank are required.');
        }

        $existing = $this->crudHandler->read('company_users', [
            'company_id' => $data['company_id'],
            'user_id' => $userId
        ], ['id'], false);

        if (!empty($existing)) {
            return $this->buildResponse(false, 'You are already a member of this company.');
        }
        $insert = $this->crudHandler->create('company_users', [
            'user_id' => $userId,
            'company_id' => $data['company_id'],
            'role' => $data['role'],
            'rank' => $data['rank'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($insert) {
            return $this->buildResponse(true, 'User added to company successfully.', ['company_user_id' => (int)$insert], false);
        }
        return $this->buildResponse(false, 'Failed to add user to company.');
    }
    public function getUserCompanies(array $data): array {
        $userId = $this->userId;
        if (!$userId) {
            return ['success' => false, 'message' => 'User ID is required.'];
        }
        $joins = [[
            'table' => 'companies',
            'on1' => 'company_users.company_id',
            'operator' => '=',
            'on2' => 'companies.id'
        ]];
        $conditions = ['company_users.user_id' => $userId];
        $columns = ['companies.id', 'companies.name', 'companies.created_at', 'companies.logo', 'company_users.role'];
        $companies = $this->crudHandler->read('company_users', $conditions, $columns, true, $joins);
        $data = $companies instanceof \Illuminate\Support\Collection ? $companies->toArray() : (array) $companies;
        return $this->buildResponse(true, 'Companies fetched successfully.', $data);
    }
    public function getCompanyEmployees(array $data): array {
        $companyId = $data['company_id'] ?? null;
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
        $joins = [[
            'table' => 'users',
            'on1' => 'company_users.user_id',
            'operator' => '=',
            'on2' => 'users.id'
        ]];
        $conditions = ['company_users.company_id' => $companyId];
        $columns = ['users.id', 'users.name', 'users.surname', 'users.email', 'users.user_image', 'company_users.role', 'company_users.rank'];
        $employees = $this->crudHandler->read('company_users', $conditions, $columns, true, $joins);
        $data = $employees instanceof \Illuminate\Support\Collection ? $employees->toArray() : (array) $employees;
        return $this->buildResponse(true, 'Company employees fetched successfully.', $data);
    }
    public function getUserCompanyRole($params) {
        $userId = $this->userId;
        $companyId = $params['company_id'];
        getLogger()->error('Company ID: ' . $companyId . ', User ID: ' . $userId);
        // company_users tablosunda ara
        $matchUser = $this->crudHandler->read('company_users', [
            'company_id' => $companyId,
            'user_id' => $userId,
        ], ['role'], true);
        if (!empty($matchUser)) {
            $role = $matchUser->first()?->role ?? null;
            if ($role) {
                return jsonResponse(true, 'Role found.', ['role' => $role], [], 200, false);
            }
        }
        // company_followers tablosunda ara
        $matchFollower = $this->crudHandler->read('company_followers', [
            'company_id' => $companyId,
            'user_id' => $userId,
        ], ['user_id'], true);
        if (!empty($matchFollower)) {
            return jsonResponse(true, 'Role found.', ['role' => 'follower']);
        }
        return jsonResponse(true, 'No relation.', ['role' => 'none']);
    }
    public function getCompanyFollowers(array $data): array {
        $companyId = $data['company_id'] ?? null;
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
        $joins = [[
            'table' => 'users',
            'on1' => 'company_followers.user_id',
            'operator' => '=',
            'on2' => 'users.id'
        ]];
        $conditions = ['company_followers.company_id' => $companyId];
        $columns = ['users.id', 'users.name', 'users.email'];
        $followers = $this->crudHandler->read('company_followers', $conditions, $columns, true, $joins);
        $data = $followers instanceof \Illuminate\Support\Collection ? $followers->toArray() : (array) $followers;
        return $this->buildResponse(true, 'Followers fetched successfully.', $data);
    }
    public function getAllCompanies(array $data): array {
        $page = (int) ($data['page'] ?? 1);
        $limit = (int) ($data['limit'] ?? 25);
        $offset = ($page - 1) * $limit;
        $search = $data['search'] ?? '';
        $orderBy = $data['orderBy'] ?? 'created_at';
        $orderDirection = strtoupper($data['orderDirection'] ?? 'DESC');
        if (!in_array($orderDirection, ['ASC', 'DESC'])) {
            $orderDirection = 'DESC';
        }
        $conditions = [];
        if (!empty($search)) {
            $conditions = [
                'name' => ['LIKE', '%' . $search . '%']
            ];
        }
        $companies = $this->crudHandler->read(
            'companies',
            $conditions,
            ['*'],
            true,
            [],
            ['limit' => $limit, 'offset' => $offset],
            ['orderBy' => [$orderBy => $orderDirection]],
            true
        );
        if (!$companies || !is_array($companies)) {
            $companies = [];
        }
        $total = $this->crudHandler->count('companies');
        return $this->buildResponse(true, 'Companies retrieved successfully.', [
            'items' => $companies,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ]
        ]);        
    }
    public function updateCompany(array $data): array {
        $companyId = $data['company_id'] ?? null;
        $userId = $this->userId;
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
        // Admin kontrolü
        $relation = $this->crudHandler->read('company_users', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'role' => 'admin'
        ], ['id'], false);
        if (!$relation) {
            return $this->buildResponse(false, 'Unauthorized.');
        }
        // Güncellenecek veriyi hazırla
        $updateData = $data;
        unset($updateData['company_id'], $updateData['user_id']);
        if (empty($updateData)) {
            return $this->buildResponse(false, 'No data provided to update.');
        }
        $updated = $this->crudHandler->update('companies', $updateData, ['id' => $companyId]);
        if ($updated) {
            return $this->buildResponse(true, 'Company updated successfully.', ['updated' => $updated], false);
        }
        return $this->buildResponse(false, 'Failed to update company.');
    }
    public function deleteCompany(array $data): array {
        $companyId = $data['company_id'] ?? null;
        $userId = $this->userId;
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
        $relation = $this->crudHandler->read('company_users', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'role' => 'admin'
        ], ['id'], false);
        if (!$relation) {
            return $this->buildResponse(false, 'Unauthorized.');
        }
        $deleted = $this->crudHandler->delete('companies', ['id' => $companyId]);
        return $this->buildResponse(true, 'Company deleted.', ['deleted' => $deleted]);
    }
}