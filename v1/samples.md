Örnek Gelen JSON (Flutter’dan)
{
  "module": "user",
  "action": "login",
  "params": {
    "email": "admin@test.com",
    "password": "123456"
  }
}

Token Oluşturma

$token = JWT::encode(['user_id' => 5], $config['jwt_secret'], $config['jwt_expiration']);

Token Çözme

$data = JWT::decode($token, $config['jwt_secret']);
if ($data === null) {
    // Invalid token
}

Kullanım Örneği (Bir Handler'da)

public static function getProfile($params)
{
    $auth = Auth::requireAuth(); // burada user_id alınır
    $userId = $auth['user_id'];
    // ardından kullanıcı profili sorgulanır
}

Başarılı yanıt
Response::success(['id' => 1, 'name' => 'John']);
başarısız yanıt
Response::error("Invalid credentials", 401);


db test
$db = DB::getInstance();
$query = $db->query("SELECT COUNT(*) FROM users");
$count = $query->fetchColumn();
