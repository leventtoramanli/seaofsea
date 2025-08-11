<?php
// modules/cv/CV.php
// Router: module=cv, action=get_cv | update_cv | list_certificates

class CV
{
    private static Crud $crud;

    private static function init(int $userId): void
    {
        self::$crud = new Crud($userId);
    }

    /** GET: cv.get_cv */
    public static function get_cv(array $params): array
    {
        $auth = Auth::requireAuth();
        $me = (int)$auth['user_id'];
        self::init($me);

        // 1 satır garantile
        $row = self::$crud->read('user_cv', ['user_id' => $me], false);
        if (!$row) {
            self::$crud->create('user_cv', [
                'user_id' => $me,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $row = self::$crud->read('user_cv', ['user_id' => $me], false) ?: [];
        }

        // Ülke/Şehir isimleri (opsiyonel, tablo yoksa null kalsın)
        $countryName = null;
        $cityName    = null;
        try {
            if (!empty($row['country_id'])) {
                $c = self::$crud->read('countries', ['id' => (int)$row['country_id']], false);
                if ($c) $countryName = $c['name'] ?? null;
            }
        } catch (\Throwable $e) {}
        try {
            if (!empty($row['city_id'])) {
                $ct = self::$crud->read('cities', ['id' => (int)$row['city_id']], false);
                if ($ct) $cityName = $ct['name'] ?? null;
            }
        } catch (\Throwable $e) {}

        // JSON/text alanları normalize et
        $data = [
            'own'                => true,
            'basic_info'         => $row['basic_info'] ?? '',
            'professional_title' => $row['professional_title'] ?? '',
            'country_id'         => isset($row['country_id']) ? (int)$row['country_id'] : null,
            'city_id'            => isset($row['city_id']) ? (int)$row['city_id'] : null,
            'country_name'       => $countryName, // Flutter kitabına uysun
            'city_name'          => $cityName,
            'address'            => $row['address'] ?? '',
            'zip_code'           => $row['zip_code'] ?? '',
            'phone'              => self::toArray($row['phone'] ?? null),
            'email'              => self::toArray($row['email'] ?? null),
            'social'             => self::toArray($row['social'] ?? null),
            'language'           => self::toArray($row['language'] ?? null),
            'education'          => self::toArray($row['education'] ?? null),
            'work_experience'    => self::toArray($row['work_experience'] ?? null),
            'skills'             => self::toArray($row['skills'] ?? null),
            'certificates'       => self::toArray($row['certificates'] ?? null),
            'seafarer_info'      => self::toArray($row['seafarer_info'] ?? null),
            'references'         => self::toArray($row['references'] ?? null),
            'access_scope'       => $row['access_scope'] ?? null,
            'updated_at'         => $row['updated_at'] ?? null,
        ];

        return ['data' => $data];
    }

    /** POST: cv.update_cv */
    public static function update_cv(array $params): array
    {
        $auth = Auth::requireAuth();
        $me = (int)$auth['user_id'];
        self::init($me);

        // satırı garanti et
        $row = self::$crud->read('user_cv', ['user_id' => $me], false);
        if (!$row) {
            self::$crud->create('user_cv', ['user_id' => $me, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $u = [];

        // Düz alanlar
        if (array_key_exists('basic_info', $params)) {
            $u['basic_info'] = (string)$params['basic_info'];
        }
        if (array_key_exists('professional_title', $params)) {
            $u['professional_title'] = trim((string)$params['professional_title']);
        }

        // Contact bloğu
        if (isset($params['contact']) && is_array($params['contact'])) {
            $c = $params['contact'];
            if (array_key_exists('country_id', $c)) $u['country_id'] = (int)$c['country_id'];
            if (array_key_exists('city_id', $c))    $u['city_id']    = (int)$c['city_id'];
            if (array_key_exists('address', $c))    $u['address']    = (string)$c['address'];
            if (array_key_exists('zip_code', $c))   $u['zip_code']   = (string)$c['zip_code'];

            if (array_key_exists('phone', $c))  $u['phone']  = self::toJsonString($c['phone'], 255); // varchar(255)
            if (array_key_exists('email', $c))  $u['email']  = self::toJsonString($c['email'], 620); // varchar(620)
            if (array_key_exists('social', $c)) $u['social'] = self::toJsonString($c['social']);     // longtext
        }

        // Liste alanları → JSON string (longtext kolonlar)
        foreach (['language','education','work_experience','skills','references','seafarer_info'] as $k) {
            if (array_key_exists($k, $params)) {
                $u[$k] = self::toJsonString($params[$k]);
            }
        }

        // Sertifikalar iki formatta gelebilir
        if (array_key_exists('certificates', $params)) {
            $u['certificates'] = self::toJsonString($params['certificates']);
        } elseif (isset($params['stcw_certificates']) && is_array($params['stcw_certificates']) && isset($params['stcw_certificates']['certificates'])) {
            $u['certificates'] = self::toJsonString($params['stcw_certificates']['certificates']);
        }

        if (empty($u)) {
            return ['message' => 'No changes'];
        }

        $ok = self::$crud->update('user_cv', $u, ['user_id' => $me]);
        if (!$ok) return ['message' => 'Update failed'];

        // updated_at DB tarafında otomatik
        $newRow = self::$crud->read('user_cv', ['user_id' => $me], false);
        return ['success' => true, 'updated_at' => $newRow['updated_at'] ?? null];
    }

    /** GET: cv.list_certificates
     * Not: Aşağıdaki tablo ad/kolonlarını kendi şemanıza göre uyarlayacağız (stcw_certificates).
     */
    public static function list_certificates(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init((int)$auth['user_id']);

        // Varsayım: stcw_certificates(id, name, stcw_code, group_id, sort_order, note)
        $rows = [];
        try {
            $rows = self::$crud->readAll('stcw_certificates', [], 'group_id ASC, sort_order ASC') ?: [];
        } catch (\Throwable $e) {
            // tablo yoksa boş dön
            $rows = [];
        }

        $data = array_map(function($r){
            return [
                'id'         => (int)($r['id'] ?? 0),
                'name'       => (string)($r['name'] ?? ''),
                'stcw_code'  => (string)($r['stcw_code'] ?? ''),
                'group_id'   => (int)($r['group_id'] ?? 0),
                'sort_order' => (int)($r['sort_order'] ?? 0),
                'note'       => (string)($r['note'] ?? ''),
            ];
        }, $rows);

        return ['data' => $data];
    }

    /* ---------------- helpers ---------------- */

    /** text/json sütunlardan dizi üretir (tek string gelirse [string]) */
    private static function toArray($raw): array
    {
        if (is_array($raw)) return $raw;
        $s = trim((string)$raw);
        if ($s === '') return [];
        $decoded = json_decode($s, true);
        if (is_array($decoded)) return $decoded;
        // JSON değilse tekli değer gibi yorumla
        return [$s];
    }

    /** Diziyi JSON stringe çevirir; maxLen verilirse kırpar (varchar güvenliği) */
    private static function toJsonString($val, int $maxLen = 0): string
    {
        $arr = is_array($val) ? $val : (($val === null || $val === '') ? [] : [$val]);
        $json = json_encode($arr, JSON_UNESCAPED_UNICODE);
        if ($maxLen > 0 && strlen($json) > $maxLen) {
            // aşırı uzunlukta kaba güvenlik: kırp
            $json = substr($json, 0, $maxLen);
        }
        return $json;
    }
}
