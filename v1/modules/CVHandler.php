<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';

class CvHandler
{
    /* ===== Public API (actions) ===== */

    // Router action: get_cv
    public static function get_cv(array $params = []): array { return self::getCV($params); }
    public static function listCertificates(array $params): array { return self::list_certificates($params); }

    public static function getCV(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud = new Crud($userId);

        // Satır yoksa oluşturma yapmıyoruz; boş data döneriz
        $cv = $crud->read('user_cv', ['user_id' => $userId], fetchAll: false);

        if (!$cv) {
            return self::ok([
                'own' => true,
                'user_id' => $userId,
                // Flutter tarafı null/[] toleranslı
            ]);
        }

        // İsimleri cities tablosundan
        $countryName = null; $cityName = null;
        if (!empty($cv['country_id'])) {
            $c = $crud->read('cities', ['id' => (int)$cv['country_id']], ['country'], false);
            $countryName = $c['country'] ?? null;
        }
        if (!empty($cv['city_id'])) {
            $c = $crud->read('cities', ['id' => (int)$cv['city_id']], ['city'], false);
            $cityName = $c['city'] ?? null;
        }

        // Normalize: text/json alanları Flutter’ın beklediği formata çevir
        $data = [
            'own'                => true,
            'basic_info'         => $cv['basic_info'] ?? '',
            'professional_title' => $cv['professional_title'] ?? '',
            'country_id'         => isset($cv['country_id']) ? (int)$cv['country_id'] : null,
            'city_id'            => isset($cv['city_id']) ? (int)$cv['city_id'] : null,
            'country_name'       => $countryName,
            'city_name'          => $cityName,
            'address'            => $cv['address'] ?? '',
            'zip_code'           => $cv['zip_code'] ?? '',
            'phone'              => self::toList($cv['phone'] ?? null),
            'email'              => self::toList($cv['email'] ?? null),
            'social'             => self::toList($cv['social'] ?? null),
            'language'           => self::toList($cv['language'] ?? null),
            'education'          => self::toList($cv['education'] ?? null),
            'work_experience'    => self::toList($cv['work_experience'] ?? null),
            'skills'             => self::toList($cv['skills'] ?? null),
            'certificates'       => self::toList($cv['certificates'] ?? null),
            'seafarer_info'      => self::toList($cv['seafarer_info'] ?? null),
            'references'         => self::toList($cv['references'] ?? null),
            'access_scope'       => $cv['access_scope'] ?? null,
            'updated_at'         => $cv['updated_at'] ?? null,
        ];

        return self::ok($data);
    }

    // Router action: get_cv_by_user_id
    public static function get_cv_by_user_id(array $params = []): array { return self::getCVByUserId($params); }

    public static function getCVByUserId(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $viewerId = (int)$auth['user_id'];
        $targetId = (int)($params['user_id'] ?? 0);
        if (!$targetId) return Response::fail('User ID missing', 400);

        $crud = new Crud($viewerId);
        $cv = $crud->read('user_cv', ['user_id' => $targetId], fetchAll: false);

        if (!$cv) {
            return self::ok(['user_id' => $targetId, 'own' => ($viewerId === $targetId)]);
        }

        // Ülke/şehir adları
        if (!empty($cv['country_id'])) {
            $c = $crud->read('cities', ['id' => (int)$cv['country_id']], ['country'], false);
            $cv['country_name'] = $c['country'] ?? null;
        }
        if (!empty($cv['city_id'])) {
            $c = $crud->read('cities', ['id' => (int)$cv['city_id']], ['city'], false);
            $cv['city_name'] = $c['city'] ?? null;
        }

        // Kullanıcı resmi (opsiyonel)
        $u = $crud->read('users', ['id' => $targetId], ['user_image'], false);
        if ($u) $cv['user_image'] = $u['user_image'] ?? null;

        $cv['own'] = ($viewerId === $targetId);

        // JSON alanları diziye çevir
        foreach (['phone','email','social','language','education','work_experience','skills','certificates','seafarer_info','references'] as $k) {
            $cv[$k] = self::toList($cv[$k] ?? null);
        }

        return self::ok($cv);
    }

    // Router action: list_certificates
    public static function list_certificates(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $rows = $crud->read(
            'certificates',
            [],
            ['id','group_id','sort_order','name','stcw_code','datelimit','medical_requirements','note'],
            fetchAll: true,
            orderBy: ['group_id' => 'ASC', 'sort_order' => 'ASC']
        );

        return self::ok($rows ?: []);
    }

    // Router action: list_countries
    public static function listCountries(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $rows = $crud->read(
            'cities',
            [],
            ['MIN(id) as id','country as name','iso2 as code2','iso3 as code'],
            fetchAll: true,
            groupBy: ['country','iso3'],
            orderBy: ['country' => 'ASC']
        );

        return self::ok($rows ?: []);
    }

    // Router action: list_cities_by_country
    public static function list_cities_by_country(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $country = $params['country'] ?? $params['countryName'] ?? null;
        if (!$country) return Response::fail('Country is required', 400);

        $rows = $crud->read(
            'cities',
            ['country' => $country, 'capital' => ['IN', ['admin','primary']]],
            ['id','city AS name'],
            fetchAll: true,
            orderBy: ['city' => 'ASC']
        );

        return self::ok($rows ?: []);
    }

    // Router action: update_cv
    public static function update_cv(array $params = []): array { return self::updateCV($params); }

    public static function updateCV(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud = new Crud($userId);

        // {"contact": {...}} gibi tek köklü payload geldiyse içeriğini al
        if (count($params) === 1 && is_array(reset($params))) {
            $params = reset($params);
        }

        $allowed = [
            'basic_info','professional_title',
            'country_id','city_id','address','zip_code',
            'phone','email','social',
            'language','education','work_experience','skills',
            'certificates','seafarer_info','references','access_scope',
            'contact', 'stcw_certificates'
        ];

        $data = array_filter($params, fn($k) => in_array($k, $allowed, true), ARRAY_FILTER_USE_KEY);

        // contact bloğu
        if (isset($data['contact']) && is_array($data['contact'])) {
            $c = $data['contact'];
            if (array_key_exists('country_id', $c)) $data['country_id'] = (int)$c['country_id'];
            if (array_key_exists('city_id', $c))    $data['city_id']    = (int)$c['city_id'];
            if (array_key_exists('address', $c))    $data['address']    = (string)$c['address'];
            if (array_key_exists('zip_code', $c))   $data['zip_code']   = (string)$c['zip_code'];
            if (array_key_exists('phone', $c))      $data['phone']      = self::toJson($c['phone']);
            if (array_key_exists('email', $c))      $data['email']      = self::toJson($c['email']);
            if (array_key_exists('social', $c))     $data['social']     = self::toJson($c['social']);
            unset($data['contact']);
        }

        // stcw_certificates fallback -> certificates
        if (!isset($data['certificates']) && isset($data['stcw_certificates']['certificates'])) {
            $data['certificates'] = self::toJson($data['stcw_certificates']['certificates']);
            unset($data['stcw_certificates']);
        }

        // JSON alanlar
        foreach (['phone','email','social','language','education','work_experience','skills','certificates','seafarer_info','references'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $data[$k] = self::toJson($data[$k]);
            }
        }

        // Basic alanlar
        if (isset($data['basic_info']))         $data['basic_info'] = (string)$data['basic_info'];
        if (isset($data['professional_title'])) $data['professional_title'] = trim((string)$data['professional_title']);

        if (empty($data)) return Response::fail('No valid CV fields provided', 400);

        $exists = $crud->read('user_cv', ['user_id' => $userId], fetchAll: false);
        if ($exists) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $crud->update('user_cv', ['user_id' => $userId], $data);
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $crud->create('user_cv', $data);
        }

        return self::ok(['updated' => true], 'CV updated');
    }

    /* ===== Helpers ===== */

    private static function ok($data = [], string $message = 'OK'): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data];
    }

    /** DB’de text/json saklanan alanlar için: string -> array */
    private static function toList($raw): array
    {
        if (is_array($raw)) return $raw;
        $s = trim((string)$raw);
        if ($s === '') return [];
        $dec = json_decode($s, true);
        if (is_array($dec)) return $dec;
        return [$s];
    }

    /** Dizi/tekil değeri JSON’a çevirir (UNESCAPED_UNICODE) */
    private static function toJson($val): string
    {
        $arr = is_array($val) ? $val : (($val === null || $val === '') ? [] : [$val]);
        return json_encode($arr, JSON_UNESCAPED_UNICODE);
    }
}
