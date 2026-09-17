<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$key = trim((string)getenv('OPENAI_API_KEY'));
$configLoaded = false;
if ($key === '' && is_file(__DIR__ . '/openai-config.php')) {
    require __DIR__ . '/openai-config.php';
    $key = trim((string)($OPENAI_API_KEY ?? ''));
    $configLoaded = true;
}

$result = [
    'ok' => true,
    'service' => 'Ramani Majengo AI diagnostics',
    'time_utc' => gmdate('c'),
    'php_version' => PHP_VERSION,
    'curl_available' => function_exists('curl_init'),
    'openai_config_file_exists' => is_file(__DIR__ . '/openai-config.php'),
    'openai_config_loaded' => $configLoaded,
    'openai_api_key_configured' => $key !== '',
    'openai_api_key_length' => $key !== '' ? strlen($key) : 0,
];

if ($key === '') {
    $result['diagnosis'] = 'OPENAI_API_KEY is not available to PHP.';
    out($result, 503);
}

if (!function_exists('curl_init')) {
    $result['diagnosis'] = 'PHP cURL is not available.';
    out($result, 503);
}

$ch = curl_init('https://api.openai.com/v1/models');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

$result['openai_http_status'] = $http;
$result['openai_reachable'] = ($response !== false && $http >= 200 && $http < 500);

if ($response === false) {
    $result['diagnosis'] = 'PHP reached a cURL/network failure before receiving an OpenAI response.';
    $result['curl_error'] = $error;
    out($result, 502);
}

$provider = json_decode((string)$response, true);
if ($http >= 200 && $http < 300) {
    $result['diagnosis'] = 'OpenAI API is reachable and the server accepted the API key.';
    $result['provider_error'] = null;
    out($result, 200);
}

$result['diagnosis'] = 'OpenAI responded, but rejected the request.';
$result['provider_error_code'] = $provider['error']['code'] ?? null;
$result['provider_error_type'] = $provider['error']['type'] ?? null;
$result['provider_error_message'] = $provider['error']['message'] ?? 'No provider message returned.';
out($result, $http >= 400 && $http < 600 ? $http : 502);
