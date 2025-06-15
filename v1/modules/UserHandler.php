<?php

class UserHandler
{
    public static function getProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        $userId = $auth['user_id'];

        $db = DB::getInstance();
        $query = $db->prepare("SELECT id, name, email, role_id, is_verified FROM users WHERE id = :id LIMIT 1");
        $query->execute(['id' => $userId]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            Response::error("User not found.", 404);
        }
        return $user;
    }
}
