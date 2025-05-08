<?php
require_once __DIR__ . '/../handlers/CRUDHandlers.php';

class PermissionHelper {
    public static function checkVisibilityScope(string $scope, int $userId, array $context): bool {
        $crud = new CRUDHandler();

        $entityId = $context['entity_id'] ?? null;
        $creatorTable = $context['created_by_table'] ?? null;
        $membershipTable = $context['membership_table'] ?? null;
        $followerTable = $context['follower_table'] ?? null;
        $roleColumn = $context['role_column'] ?? 'role';
        $approvedOnly = $context['approved_followers_only'] ?? false;

        switch ($scope) {
            case 'all':
                return true;

            case 'own':
                if ($creatorTable && $entityId) {
                    return $crud->exists($creatorTable, [
                        'id' => $entityId,
                        'created_by' => $userId
                    ]);
                }
                return false;

            case 'ownperm':
                $isOwner = $creatorTable && $entityId && $crud->exists($creatorTable, [
                    'id' => $entityId,
                    'created_by' => $userId
                ]);
                $isApprovedFollower = $followerTable && $crud->exists($followerTable, array_merge(
                    [
                        'user_id' => $userId,
                        $context['entity'] . '_id' => $entityId
                    ],
                    $approvedOnly ? ['approved' => 1] : []
                ));
                return $isOwner || $isApprovedFollower;

            case 'followers':
                if ($followerTable) {
                    return $crud->exists($followerTable, [
                        'user_id' => $userId,
                        $context['entity'] . '_id' => $entityId
                    ]);
                }
                return false;

            case 'approved':
                if ($followerTable) {
                    return $crud->exists($followerTable, [
                        'user_id' => $userId,
                        $context['entity'] . '_id' => $entityId,
                        'approved' => 1
                    ]);
                }
                return false;

            case 'members':
                if ($membershipTable) {
                    return $crud->exists($membershipTable, [
                        'user_id' => $userId,
                        $context['entity'] . '_id' => $entityId
                    ]);
                }
                return false;

            case 'adm':
                if ($membershipTable) {
                    return $crud->exists($membershipTable, [
                        'user_id' => $userId,
                        $context['entity'] . '_id' => $entityId,
                        $roleColumn => 'admin'
                    ]);
                }
                return false;

            default:
                return false;
        }
    }
}
