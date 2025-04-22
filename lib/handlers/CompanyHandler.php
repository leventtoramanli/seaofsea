<?php

require_once __DIR__ . '/../handlers/CRUDHandlers.php';

class CompanyHandler {
    private $crudHandler;

    public function __construct() {
        $this->crudHandler = new CRUDHandler();
    }

    public function createCompany(array $data): array {
        $userId = getUserIdFromToken();

        if (!$userId || empty($data['name'])) {
            return [
                'success' => false,
                'message' => 'Company name is required.'
            ];
        }

        $companyId = $this->crudHandler->create('companies', [
            'name' => $data['name'],
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($companyId) {
            $this->crudHandler->create('user_company', [
                'user_id' => $userId,
                'company_id' => $companyId,
                'role' => 'admin',
                'is_active' => true,
            ]);

            return [
                'success' => true,
                'message' => 'Company created successfully.',
                'data' => ['company_id' => $companyId]
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to create company.'
        ];
    }

    public function getUserCompanies(array $data): array {
        $userId = getUserIdFromToken();

        if (!$userId) {
            return ['success' => false, 'message' => 'User ID is required.'];
        }

        $joins = [[
            'table' => 'companies',
            'on1' => 'user_company.company_id',
            'operator' => '=',
            'on2' => 'companies.id'
        ]];

        $conditions = ['user_company.user_id' => $userId];
        $columns = ['companies.id', 'companies.name', 'companies.created_at'];

        $companies = $this->crudHandler->read('user_company', $conditions, $columns, true, $joins);

        return [
            'success' => true,
            'message' => 'Companies fetched successfully.',
            'data' => $companies
        ];
    }

    public function getAllCompanies(array $data): array {
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 25);
        $offset = ($page - 1) * $limit;

        $companies = $this->crudHandler->read(
            'companies',
            [],
            ['id', 'name', 'logo', 'created_at'],
            true,
            [],
            ['limit' => $limit, 'offset' => $offset],
            ['orderBy' => ['created_at' => 'desc']],
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
        $companyId = $data['company_id'] ?? null;
        $name = $data['name'] ?? null;
        $userId = getUserIdFromToken();

        if (!$companyId || !$name) {
            return ['success' => false, 'message' => 'Company ID and name are required.'];
        }

        $relation = $this->crudHandler->read('user_company', [
            'company_id' => $companyId,
            'user_id' => $userId,
            'role' => 'admin'
        ], ['id'], false);

        if (!$relation) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $updated = $this->crudHandler->update('companies', ['name' => $name], ['id' => $companyId]);
        return ['success' => true, 'message' => 'Company updated.', 'data' => ['updated' => $updated]];
    }

    public function deleteCompany(array $data): array {
        $companyId = $data['company_id'] ?? null;
        $userId = getUserIdFromToken();

        if (!$companyId) {
            return ['success' => false, 'message' => 'Company ID is required.'];
        }

        $relation = $this->crudHandler->read('user_company', [
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
