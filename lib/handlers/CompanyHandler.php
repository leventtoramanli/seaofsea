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

    public function __construct() {
        $this->crudHandler = new CRUDHandler();
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }
        if (!self::$loggerInfo) {
            self::$loggerInfo = getLoggerInfo(); // Merkezi logger
        }
    }

    public function createCompany(array $data): array
    {
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
            'showMessage' => true
        ];

        $userId = getUserIdFromToken();

        if (!$userId || empty($data['name'])) {
            $response['message'] = 'Company name is required.';
            return $response;
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Invalid email address.';
            return $response;
        }

        $existing = $this->crudHandler->read('companies', ['email' => $data['email']], ['id'], false);

        if (!empty($existing)) {
            $response['message'] = 'A company with this email already exists.';
            return $response;
        }

        $companyId = $this->crudHandler->create('companies', [
            'name' => $data['name'],
            'email' => $data['email'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($companyId) {
            $response['success'] = true;
            $response['message'] = 'Company created successfully.';
            $response['data'] = ['company_id' => (int) $companyId];
            $response['showMessage'] = true;
        } else {
            $response['showMessage'] =  true;
        }

        return $response;
    }

      
    
    public function createUserCompany(array $data): array
    {
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
            'errors' => [],
            'showMessage' => true
        ];

        $userId = getUserIdFromToken();

        if (!$userId || empty($data['company_id']) || empty($data['role']) || empty($data['rank'])) {
            $response['message'] = 'Company ID, role and rank are required.';
            return $response;
        }

        $existing = $this->crudHandler->read('company_users', [
            'company_id' => $data['company_id'],
            'user_id' => $userId
        ], ['id'], false);

        if (!empty($existing)) {
            $response['message'] = 'You are already a member of this company.';
            return $response;
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
            $response['success'] = true;
            $response['message'] = 'User added to company successfully.';
            $response['data'] = ['company_user_id' => (int)$insert];
            $response['showMessage'] = false;
        } else {
            $response['message'] = 'Failed to add user to company.';
        }

        return $response;
    }


    public function getUserCompanies(array $data): array {
        $userId = getUserIdFromToken();
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
        $columns = ['companies.id', 'companies.name', 'companies.created_at', 'companies.logo'];
    
        $companies = $this->crudHandler->read('company_users', $conditions, $columns, true, $joins);
    
        $data = $companies instanceof \Illuminate\Support\Collection ? $companies->toArray() : (array) $companies;
    
        return [
            'success' => true,
            'message' => 'Companies fetched successfully.',
            'data' => $data,
        ];
    }    

    public function getAllCompanies(array $data): array {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 25);
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        $orderBy = $_GET['orderBy'] ?? 'created_at';
        $orderDirection = strtoupper($_GET['orderDirection'] ?? 'DESC');
        
        if (!in_array($orderDirection, ['ASC', 'DESC'])) {
            $orderDirection = 'DESC'; // Güvenlik: sadece ASC veya DESC olsun
        }
    
        $conditions = [];
        if (!empty($search)) {
            $conditions = [
                ['name', 'LIKE', "%$search%"]
            ];
        }
    
        $companies = $this->crudHandler->read(
            'companies',
            $conditions,
            ['id', 'name', 'logo', 'created_at'],
            true,
            [],
            ['limit' => $limit, 'offset' => $offset],
            ['orderBy' => [$orderBy => $orderDirection]],
            true
        );
    
        $total = $this->crudHandler->count('companies');
    
        return [
            'success' => true,
            'message' => 'Companies retrieved successfully.',
            'data' => [
                'items' => $companies,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]
        ];
    }    

    public function updateCompany(array $data): array {
        $response = [
            'success' => false,
            'message' => '',
            'data' => [],
            'errors' => [],
            'showMessage' => true
        ];
    
        $companyId = $data['company_id'] ?? null;
        $userId = getUserIdFromToken();
    
        if (!$companyId) {
            $response['message'] = 'Company ID is required.';
            return $response;
        }
    
        // Admin kontrolü
        $relation = $this->crudHandler->read('company_users', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'role' => 'admin'
        ], ['id'], false);
    
        if (!$relation) {
            $response['message'] = 'Unauthorized.';
            return $response;
        }
    
        // Güncellenecek veriyi hazırla
        $updateData = $data;
        unset($updateData['company_id'], $updateData['user_id']); // Bunlar update edilmeyecek
    
        if (empty($updateData)) {
            $response['message'] = 'No data provided to update.';
            return $response;
        }
    
        $updated = $this->crudHandler->update('companies', $updateData, ['id' => $companyId]);
    
        if ($updated) {
            $response['success'] = true;
            $response['message'] = 'Company updated successfully.';
            $response['data'] = ['updated' => $updated];
            $response['showMessage'] = false;
        } else {
            $response['message'] = 'Failed to update company.';
        }
    
        return $response;
    }
    

    public function deleteCompany(array $data): array {
        $companyId = $data['company_id'] ?? null;
        $userId = getUserIdFromToken();

        if (!$companyId) {
            return ['success' => false, 'message' => 'Company ID is required.'];
        }

        $relation = $this->crudHandler->read('company_users', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'role' => 'admin'
        ], ['id'], false);

        if (!$relation) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $deleted = $this->crudHandler->delete('companies', ['id' => $companyId]);
        return ['success' => true, 'message' => 'Company deleted.', 'data' => ['deleted' => $deleted]];
    }
}
