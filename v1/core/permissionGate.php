<?php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/Response.php';

class Gate
{
    /**
     * Checks if the authenticated user has the specified permission.
     * If not verified or not authorized, throws a 403 response.
     */
    // Gate.php

    public static function check(string $permissionCode, ?int $companyId = null): void
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']); // userId context

        // Kullanıcıyı DB'den çek
        $user = $crud->read('users', ['id' => $auth['user_id']], false);
        if (!$user) {
            Response::error("User not found.", 401);
        }

        // Blokaj kontrolü
        if (!empty($user['blocked_until']) && strtotime($user['blocked_until']) > time()) {
            Response::error("Your account is blocked until {$user['blocked_until']}.", 403);
        }

        // Email verify kontrolü
        if ((int)($user['is_verified'] ?? 0) === 0) {
            Response::error("Email verification is required to perform this action.", 403);
        }

        // İzin kontrolü
        $hasPermission = PermissionService::hasPermission($auth['user_id'], $permissionCode, $companyId);
        if (!$hasPermission) {
            Response::error("You do not have permission: {$permissionCode}", 403);
        }
    }

    public static function checkPermissionOnly(string $permissionCode, ?int $companyId = null): void
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);
        $user = $crud->read('users', ['id' => $auth['user_id']], false);

        if (!$user) {
            Response::error("User not found.", 401);
        }

        // Blokaj kontrolü (email kontrolü yok)
        if (!empty($user['blocked_until']) && strtotime($user['blocked_until']) > time()) {
            Response::error("Your account is blocked until {$user['blocked_until']}.", 403);
        }

        $hasPermission = PermissionService::hasPermission($auth['user_id'], $permissionCode, $companyId);
        if (!$hasPermission) {
            Response::error("You are not authorized for this action.", 403);
        }
    }


    /**
     * Checks only email verification status.
     */
    public static function checkVerified(): void
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);
        $user = $crud->read('users', ['id' => $auth['user_id']], false);
        if (!$user) {
            Response::error("User not found.", 401);
        }
        if ((int)($user['is_verified'] ?? 0) === 0) {
            Response::error("Email verification is required.", 403);
        }
    }

    /**
     * Checks only blocked status.
     */
    public static function checkBlocked(): void
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);
        $user = $crud->read('users', ['id' => $auth['user_id']], false);

        if (!$user) {
            Response::error("User not found.", 401);
        }

        if (!empty($user['blocked_until']) && strtotime($user['blocked_until']) > time()) {
            Response::error("Your account is blocked until {$user['blocked_until']}.", 403);
        }
    }

    /**
     * Returns true/false without throwing error. Use in conditional checks.
     */
    public static function allows(string $permissionCode, ?int $companyId = null): bool
    {
        $auth = Auth::requireAuth();
        return PermissionService::hasPermission($auth['user_id'], $permissionCode, $companyId);
    }
}
