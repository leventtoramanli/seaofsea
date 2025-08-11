<?php

$url = "http://localhost/seaofsea/v1/index.php";

$data = [
    "module" => "auth",
    "action" => "login",
    "params" => [
        "email" => "leventtoramanli@gmail.com",
        "password" => "145326326lL",
        "device_uuid" => "test-uuid-1234",
        "device_name" => "Test PHP Client",
        "platform" => "php-script"
    ]
];

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

echo "🎯 URL: $url\n";
echo "📤 Gönderilen Veri:\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

if (isset($http_response_header)) {
    echo "📩 HTTP Yanıt Başlıkları:\n";
    foreach ($http_response_header as $header) {
        echo $header . "\n";
    }
    echo "\n";
}

if ($response === false) {
    echo "❌ Sunucudan yanıt alınamadı.\n";
    $error = error_get_last();
    echo "PHP Hatası: " . $error['message'] . "\n";
} else {
    echo "✅ Sunucudan gelen yanıt:\n$response\n";
}
