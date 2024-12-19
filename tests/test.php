<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/initializer.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/LoggerHelper.php';
require_once __DIR__ . '/../middlewares/CorsMiddleware.php';

use App\Utils\LoggerHelper;
use App\Middlewares\CorsMiddleware;

// CORS Middleware
\CorsMiddleware::handle();

// Loglama
LoggerHelper::getLogger()->info("CRUD Test Page started successfully");

// Helper function to make JSON requests
function sendRequest($method, $url, $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($data))
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo 'Request Error: ' . curl_error($ch);
    }

    curl_close($ch);

    return [
        'status' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

// HTML Form Output
echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRUD Test Page</title>
</head>
<body>
    <h1>CRUD Test Page</h1>
    <form action="" method="POST">
        <label for="method">HTTP Method:</label>
        <select name="method" id="method">
            <option value="POST">POST (Create)</option>
            <option value="GET">GET (Read)</option>
            <option value="PUT">PUT (Update)</option>
            <option value="DELETE">DELETE (Delete)</option>
        </select><br><br>

        <label for="url">API URL:</label>
        <input type="text" name="url" id="url" placeholder="http://localhost/api" required><br><br>

        <label for="data">JSON Data (if applicable):</label><br>
        <textarea name="data" id="data" rows="5" cols="50" placeholder=\'{"key":"value"}\'></textarea><br><br>

        <button type="submit">Send Request</button>
    </form>

    <h2>Response:</h2>
    <pre>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = $_POST['method'] ?? 'GET';
    $url = $_POST['url'] ?? '';
    $data = !empty($_POST['data']) ? json_decode($_POST['data'], true) : null;

    if ($url) {
        $response = sendRequest($method, $url, $data);
        print_r($response);
    } else {
        echo "No URL provided.";
    }
}

echo '</pre>
</body>
</html>';
?>
