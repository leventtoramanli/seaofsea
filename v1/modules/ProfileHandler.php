<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/log.php';

class ProfileHandler
{
    private static Crud $crud;

    private static function init(int $userId): void
    {
        self::$crud = new Crud($userId);
    }

    /**
     * GET / profile.getProfile
     * - id verilmezse: kendi profili
     * - id verilirse: başka profil; görmek için en az 'profile.view' gerekir
     * Dönen veri: doğrudan kullanıcı objesi (Router bunu data içine sarar)
     */
    public static function getProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init((int)$auth['user_id']);

        $targetId = !empty($params['id']) ? (int)$params['id'] : (int)$auth['user_id'];

        // hedef kullanıcı
        $user = self::$crud->read('users', ['id' => $targetId], false);
        // BU BLOĞU DEĞİŞTİR (getProfile içindeki "User not found" early return)
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
                'code'    => 404,
                'data'    => [
                    'ui' => [
                        'show'     => true,
                        'variant'  => 'dialog',
                        'severity' => 'error',
                        'title'    => 'User not found',
                        'message'  => 'User not found',
                        'duration' => 0,
                        'nextAction' => null,
                    ],
                ],
            ];
        }

        // başkasının profili → profile.view gerekir
        if ($targetId !== (int)$auth['user_id']) {
            if (!PermissionService::hasPermission((int)$auth['user_id'], 'profile.view')) {
                return ['message' => 'Not authorized to view this profile'];
            }
        }

        // Sadece güvenli alanlar
        $allowed = [
            'id','name','surname','email','role_id','dob','pob','gender','maritalStatus',
            'user_image','cover_image','bio',
            'email_notifications','app_notifications','weekly_summary','is_verified'
        ];
        $filtered = array_intersect_key($user, array_flip($allowed));

        // Frontend flag’leri
        $filtered['isOwner']        = ($targetId === (int)$auth['user_id']);
        $filtered['emailVerified']  = (int)($user['is_verified'] ?? 0) === 1;

        return $filtered;
    }

    public static function updateProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        $me   = (int)$auth['user_id'];
        self::init($me);

        $targetId = isset($params['user_id']) ? (int)$params['user_id'] : $me;

        if ($targetId !== $me) {
            return ['message' => 'You cannot update another user\'s profile.'];
        }

        $meRow = self::$crud->read('users', ['id' => $me], ['id','email','is_verified'], false);
        // ESKİ BLOK YERİNE KOY
        if (!$meRow) {
            return [
                'success' => false,
                'message' => 'User not found',
                'code'    => 401,
                'data'    => [
                    'ui' => [
                        'show'     => true,
                        'variant'  => 'dialog', // 'dailog' değil
                        'severity' => 'error',
                        'title'    => 'Unauthorized',
                        'message'  => 'User not found',
                        'duration' => 0,
                        'nextAction' => null,
                    ],
                ],
            ];
        }

        if (!PermissionService::hasPermission($me, 'user.update_own')) {
            return [
                'success' => false,
                'message' => 'Not allowed to update your profile.',
                'code'    => 403,
                'data'    => [
                    'ui' => [
                        'show'     => true,
                        'variant'  => 'snackbar',
                        'severity' => 'warning',
                        'title'    => 'Permission denied',
                        'message'  => 'Not allowed to update your profile.',
                        'duration' => 3500,
                        'nextAction' => null,
                    ],
                ],
            ];
        }

        if ((int)($meRow['is_verified'] ?? 0) === 0) {
            return [
                'success' => false,
                'message' => 'Please verify your email before updating profile.',
                'code'    => 403,
                'data'    => [
                    'ui' => [
                        'show'     => true,
                        'variant'  => 'snackbar',
                        'severity' => 'warning',
                        'title'    => 'Email verification required',
                        'message'  => 'Please verify your email before updating profile.',
                        'duration' => 4000,
                        'nextAction' => ['module'=>'auth','action'=>'send_verification_email','params'=>[]],
                    ],
                ],
            ];
        }

        $fieldTypes = [
            'name'                => 'string',
            'surname'             => 'string',
            'dob'                 => 'date',
            'pob'                 => 'nullable_string',
            'gender'              => 'nullable_string',  // enum kontrolü aşağıda
            'maritalStatus'       => 'nullable_string',  // enum kontrolü aşağıda
            'bio'                 => 'nullable_string',
            'email_notifications' => 'bool',
            'app_notifications'   => 'bool',
            'weekly_summary'      => 'bool',
            // 'email' özel blokla işlenecek
            // 'password' özel blokla işlenecek
        ];

        $allowedGender  = ['Male','Female','Other'];
        $allowedMarital = ['Single','Married','Engaged'];

        $nullable = static function($v) {
            if ($v === '' || $v === 'null') return null;
            return $v;
        };

        $data = [];
        $emailChanged = false;

        if (array_key_exists('email', $params)) {
            $newEmail = trim((string)$params['email']);
            $oldEmail = (string)($meRow['email'] ?? '');
            if ($newEmail !== '' && $newEmail !== $oldEmail) {
                // Crud::read($table, $where, $columns=['*'], $multi=false)
                $exists = self::$crud->read('users', ['email' => $newEmail], ['id'], false);
                if ($exists && (int)$exists['id'] !== (int)$targetId) {
                    return [
                        'success' => false,
                        'message' => 'This email is already in use.',
                        'code'    => 422,
                        'data'    => [
                            'ui' => [
                                'show'      => true,
                                'variant'   => 'dialog',
                                'severity'  => 'warning',
                                'title'     => 'Email already in use',
                                'duration'  => 0,
                                'nextAction'=> null,
                            ],
                        ],
                    ];
                }
                $data['email'] = $newEmail;
                $data['is_verified'] = 0; // e-posta değiştiyse tekrar doğrulama
                $emailChanged = true;
            }
        }

        if (!empty($params['password'])) {
            $data['password'] = password_hash((string)$params['password'], PASSWORD_BCRYPT);
        }

        foreach ($fieldTypes as $k => $type) {
            if (!array_key_exists($k, $params)) continue;

            $val = self::normalizeValue($params[$k], $type);

            if ($k === 'gender') {
                $val = $nullable(is_string($val) ? trim($val) : $val);
                if ($val !== null && !in_array($val, $allowedGender, true)) {
                    return ['message' => 'Invalid gender. Allowed: '.implode(',', $allowedGender)];
                }
            }

            if ($k === 'maritalStatus') {
                $val = $nullable(is_string($val) ? trim($val) : $val);
                if ($val !== null && !in_array($val, $allowedMarital, true)) {
                    return ['message' => 'Invalid maritalStatus. Allowed: '.implode(',', $allowedMarital)];
                }
            }

            if (in_array($k, ['pob','bio'], true)) {
                $val = $nullable(is_string($val) ? trim($val) : $val);
            }

            $data[$k] = $val;
        }

        if (empty($data)) {
            return [
                'success' => false,
                'updated' => false,
                'emailReverifyRequired' => false,
                'message' => 'Nothing to update.',
                'data' => $data
            ];
        }

        $ok = self::$crud->update('users', $data, ['id' => $targetId]);
        if (!$ok) {
            return ['message' => 'Profile update failed'];
        }

        if (class_exists('Audit')) {
            try {
                Audit::log(
                    $me,
                    'user',
                    $targetId,
                    'profile.update',
                    ['fields' => array_keys($data)],
                    Request::ip(),
                    Request::userAgent()
                );
            } catch (\Throwable $e) {
                Logger::error('Audit log failed in updateProfile: '.$e->getMessage());
            }
        }

        return [
            'success' => true,
            'message' => 'Profile updated',
            'code'    => 200,
            'data'    => [
                'updated' => true,
                'emailReverifyRequired' => $emailChanged,
                'ui' => $emailChanged ? [
                    'show'      => true,
                    'variant'   => 'snackbar',
                    'severity'  => 'info',
                    'title'     => 'Email changed',
                    'message'   => 'Email changed. Please verify your new email.',
                    'duration'  => 4000,
                    'nextAction'=> ['module'=>'auth','action'=>'send_verification_email','params'=>[]],
                ] : null,
            ],
        ];
    }

    private static function normalizeValue($value, string $type)
    {
        switch ($type) {
            case 'int':              return (int)$value;
            case 'bool':             return ($value === true || $value === '1' || $value === 1) ? 1 : 0;
            case 'json':             return json_encode($value, JSON_UNESCAPED_UNICODE);
            case 'date':             return date('Y-m-d', strtotime((string)$value));
            case 'nullable_string':  $t = trim((string)$value); return $t !== '' ? $t : null;
            case 'string':
            default:                 return trim((string)$value);
        }
    }
}
