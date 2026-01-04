<?php

define('RATE_LIMIT_MAX_REQUESTS', 60); // Örn: 60 saniyede 20 istek
define('RATE_LIMIT_WINDOW_SECONDS', 60);
define('RATE_LIMIT_STORAGE_PATH', __DIR__ . '/../storage/rate_limits/');

class RateLimiter
{
    public static function checkDevice(string $deviceUuid): bool
    {
        if (!is_dir(RATE_LIMIT_STORAGE_PATH)) {
            mkdir(RATE_LIMIT_STORAGE_PATH, 0777, true);
        }

        $file = RATE_LIMIT_STORAGE_PATH . md5('device_' . $deviceUuid) . '.json';
        $now = time();
        $requests = [];

        if (file_exists($file)) {
            $requests = json_decode(file_get_contents($file), true) ?? [];
            $requests = array_filter($requests, fn($t) => $t > ($now - RATE_LIMIT_WINDOW_SECONDS));
        }

        if (count($requests) >= RATE_LIMIT_MAX_REQUESTS) {
            return false;
        }

        $requests[] = $now;
        file_put_contents($file, json_encode($requests));
        return true;
    }
}


class Router
{
    private static function moduleToHandlerClass(string $module): string
    {
        // 1) camelCase -> "camel Case"
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $module);

        // 2) snake/kebab/space -> normalize
        $s = strtolower((string) $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $parts = array_values(array_filter(explode(' ', trim($s))));

        // 3) StudlyCase
        $studly = '';
        foreach ($parts as $p)
            $studly .= ucfirst($p);

        return $studly . 'Handler';
    }

    public static function dispatch(array $input): void
    {
        $params = $input['params'] ?? [];
        $deviceUuid = $params['device_uuid'] ?? null;

        if (!$deviceUuid) {
            Response::error("Missing device UUID", 400);
            return;
        }

        if (!RateLimiter::checkDevice($deviceUuid)) {
            Response::error("Too many requests from this device. Please wait.", 429);
            return;
        }

        if (!isset($input['module']) || !isset($input['action'])) {
            Response::error("Invalid request: 'module' and 'action' are required.", 400);
            return;
        }

        $module = self::moduleToHandlerClass((string)$input['module']);
        $action = $input['action'];

        if (!class_exists($module)) {
            Response::error("Module not found: $module", 404);
            return;
        }

        if (!method_exists($module, $action)) {
            Response::error("Action not found: $action", 404);
            return;
        }

        try {
            $result = call_user_func([$module, $action], $params);
            Response::success($result ?? []);
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $safe = in_array($code, [400, 401, 403, 404, 409, 422], true) ? $code : 500;
            if ($safe === 500) {
                Logger::exception($e, "Router dispatch failed: {$input['module']}.{$input['action']}");
                Response::error("An error occurred during processing.", 500);
            } else {
                Logger::error("Handled error ({$safe}) {$input['module']}.{$input['action']}: " . $e->getMessage());
                Response::error($e->getMessage(), $safe);
            }
        }
    }
}
