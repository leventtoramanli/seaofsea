<?php

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/FormSchemaProvider.php';

class ProfileFormsHandler
{
    public static function getForm(array $params): void
    {
        $formId = $params['formId'] ?? ($params['_routeParam'] ?? ''); // /v1/forms/{formId} desteği
        $schema = FormSchemaProvider::get($formId);
        if (!$schema) Response::error("Form not found", 404);

        Response::success(['formId' => $formId, 'schema' => $schema]);
    }

    public static function saveUserSettings(array $params): void
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['user_id'];

        $data = $params['data'] ?? null; // { key: value } map
        if (!is_array($data)) Response::error("Invalid data", 422);

        $schema = FormSchemaProvider::get('user_settings');
        if (!$schema) Response::error("Form not found", 404);

        // Şemaya dayalı basit doğrulama (minimum)
        $allowedKeys = array_column($schema['fields'], 'key');
        $filtered = array_intersect_key($data, array_flip($allowedKeys));

        $crud = new Crud($userId);
        // Mevcut extra_data ile birleştir
        $existing = $crud->read('users', ['id' => $userId]);
        if (!$existing) Response::error("User not found", 404);

        $oldExtra = json_decode($existing[0]['extra_data'] ?? 'null', true) ?? [];
        $newExtra = array_merge($oldExtra, $filtered);

        $crud->update('users', ['id' => $userId], ['extra_data' => json_encode($newExtra)]);

        Response::success(['saved' => true, 'extra_data' => $newExtra]);
    }
}
