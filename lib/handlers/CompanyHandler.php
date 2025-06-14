<?php
require_once __DIR__ . '/../handlers/CRUDHandlers.php';
require_once __DIR__ . '/../handlers/UserHandler.php';
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
        self::$logger->error('CompanyHandler buildResponse', ['success' => $success, 'message' => $message, 'data' => $data, 'showMessage' => $showMessage, 'errors' => $errors]);
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
        $createArray=[
            'user_id' => $userId,
            'company_id' => $data['company_id'],
            'role' => $data['role'],
            'rank' => $data['rank'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if($data['role'] == 'admin'){
            $createArray['status'] = 'approved';
        }
        $insert = $this->crudHandler->create('company_users', $createArray);
        if ($insert) {
            return $this->buildResponse(true, 'User added to company successfully.', ['company_user_id' => (int)$insert], false);
        }
        return $this->buildResponse(false, 'Failed to add user to company.');
    }
    public function getCompanyUsers(array $data = []): array {
        $userId = $this->userId;
        if (!$userId) {
            return $this->buildResponse(false, 'User ID is required.');
        }
    
        $companyId = $data['company_id'] ?? null;
        $role = $data['role'] ?? null;
        $rank = $data['rank'] ?? null;
    
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
    
        $joins = [
            [
                'type' => 'inner',
                'table' => 'users',
                'on1' => 'company_users.user_id',
                'operator' => '=',
                'on2' => 'users.id'
            ],
            [
                'type' => 'left',
                'table' => 'users as first_approver',
                'on1' => 'company_users.approvalF',
                'operator' => '=',
                'on2' => 'first_approver.id'
            ],
            [
                'type' => 'left',
                'table' => 'users as second_approver',
                'on1' => 'company_users.approvalS',
                'operator' => '=',
                'on2' => 'second_approver.id'
            ],
        ];
    
        $conditions = ['company_users.company_id' => $companyId];
    
        if ($role !== null) {
            $conditions['company_users.role'] = $role;
        }
        if ($rank !== null) {
            $conditions['company_users.rank'] = $rank;
        }
    
        $columns = [
            'users.id',
            'users.name',
            'users.surname',
            'users.user_image',
            'company_users.role',
            'company_users.rank',
            'company_users.status',
            'company_users.created_at',
            'company_users.approvalF',
            'company_users.approvalS',
            'first_approver.name as approvalF_name',
            'first_approver.surname as approvalF_surname',
            'second_approver.name as approvalS_name',
            'second_approver.surname as approvalS_surname',
        ];
    
        $users = $this->crudHandler->read(
            'company_users',
            $conditions,
            $columns,
            true,
            $joins,
            [], // Pagination yok
            [],
            true // asArray true dönsün
        );
    
        return $this->buildResponse(true, 'Users fetched successfully.', ['data' => $users]);
    }
    
    public function getUserCompanies(array $data = []): array {
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
        $columns = ['companies.id', 'companies.name', 'companies.created_at', 'companies.logo', 'company_users.role', 'company_users.rank', 'company_users.status'];
        $companies = $this->crudHandler->read('company_users', $conditions, $columns, true, $joins);
        $data = $companies instanceof \Illuminate\Support\Collection ? $companies->toArray() : (array) $companies;
        return $this->buildResponse(true, 'Companies fetched successfully.', $data);
    }
    public function getPositionsByHandler(array $data): array {
        $handler = $data['handler'];
        $column = $handler['column'];
        $value = $handler['value'];
        $columns = $data['columns'] ?? ['*'];
    
        // Kolon ismini whitelist'te kontrol et
        $allowedColumns = [
            'id',
            'name',
            'category',
            'description',
            'created_at',
            'department',
            'area'
        ];
        if (!in_array($column, $allowedColumns)) {
            return $this->buildResponse(false, 'Invalid column name.', [], true);
        }
    
        $conditions = [$column => $value];
        $positions = $this->crudHandler->read(
            'company_positions',
            $conditions,
            $columns,
            true
        );
    
        $data = $positions instanceof \Illuminate\Support\Collection
            ? $positions->toArray()
            : (array) $positions;
    
        return $this->buildResponse(true, 'Positions fetched successfully.', $data);
    }    
    public function getPositionAreas(array $data): array {
        try {
            $areas = $this->crudHandler->read(
                'company_positions',
                [],
                ['area', 'category'],
                true,
                [],
                [],
                ['groupBy' => ['area', 'category']],
                true
            );
    
            if (empty($areas)) {
                return $this->buildResponse(false, 'No areas found.', []);
            }
    
            // Kategorilere göre gruplandır
            $grouped = [];
            foreach ($areas as $row) {
                $cat = $row['category'] ?? 'Other';
                $area = $row['area'] ?? '';
                if (!isset($grouped[$cat])) {
                    $grouped[$cat] = [];
                }
                if (!in_array($area, $grouped[$cat]) && !empty($area)) {
                    $grouped[$cat][] = $area;
                }
            }
    
            return $this->buildResponse(true, 'Areas fetched successfully.', $grouped);
        } catch (Exception $e) {
            return $this->buildResponse(false, 'Error fetching areas.', [], true, ['exception' => $e->getMessage()]);
        }
    }
    public function getPositionsByArea(array $data): array {
        try {
            if (empty($data['area'])) {
                return $this->buildResponse(false, 'Area is required.');
            }
            $positions = $this->crudHandler->read(
                'company_positions',
                ['area' => $data['area']],
                ['name'],
                true
            );
    
            if (empty($positions)) {
                return $this->buildResponse(false, 'No positions found.');
            }
            return $this->buildResponse(true, 'Positions fetched successfully.', $positions->toArray());
        } catch (Exception $e) {
            return $this->buildResponse(false, 'Error fetching positions.', [], true, ['exception' => $e->getMessage()]);
        }
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
    
    public function getCompanyDetail(array $data): array {
        $companyId = $data['company_id'] ?? null;
        if (!$companyId) {
            return $this->buildResponse(false, 'Company ID is required.');
        }
        if (!empty($company['company_type_ids'])) {
            $company['company_type_ids'] = json_decode($company['company_type_ids'], true);
        }        
        $company = (array) $this->crudHandler->read('companies', ['id' => $companyId], ['*'], false);
        if (!$company) {
            return $this->buildResponse(false, 'Company not found.');
        }
        return $this->buildResponse(true, 'Company detail retrieved successfully.', $company);
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
    
        if (isset($updateData['contact_info']) && is_array($updateData['contact_info'])) {
            $updateData['contact_info'] = json_encode($updateData['contact_info'], JSON_UNESCAPED_UNICODE);
        }
    
        if (isset($data['company_type_ids'])) {
            $companyTypeIds = $data['company_type_ids'];
            
            if (!is_array($companyTypeIds)) {
                return $this->buildResponse(false, 'Invalid company_type_ids format', [], true);
            }
        
            $updateData['company_type_ids'] = !empty($companyTypeIds)
                ? json_encode($companyTypeIds)
                : null;
        }
    
        if (empty($updateData)) {
            return $this->buildResponse(false, 'No data provided to update.');
        }
    
        $updated = $this->crudHandler->update('companies', $updateData, ['id' => $companyId]);
    
        if ($updated) {
            return $this->buildResponse(true, 'Company updated successfully.', ['updated' => $updated], false);
        }
        return $this->buildResponse(false, 'Failed to update company.');
    }
    public function getCompanyTypes(array $data): array {
        try {
            $conditions = [];
    
            // Eğer sadece belirli ID'ler isteniyorsa (örneğin sayfada yalnızca gösterim için)
            if (!empty($data['filter_ids']) && is_array($data['filter_ids'])) {
                $conditions[] = [
                    'column' => 'id',
                    'operator' => 'IN',
                    'value' => $data['filter_ids'],
                ];
            }
    
            $types = $this->crudHandler->read(
                'company_types',
                $conditions,
                ['id', 'name', 'description'],
                true
            );
    
            if (empty($types)) {
                return $this->buildResponse(false, 'No company types found.', []);
            }
    
            return $this->buildResponse(true, 'Company types retrieved successfully.', $types->toArray());
        } catch (Exception $e) {
            return $this->buildResponse(false, 'Error fetching company types.', [], true, ['exception' => $e->getMessage()]);
        }
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