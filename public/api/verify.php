<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/bootstrap.php';

// CORS for bot servers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = [];
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
        $input = $j;
    }
}
$input = array_merge($_GET, $_POST, $input);

$key = (string) ($input['license_key'] ?? $input['key'] ?? '');
$domain = (string) ($input['domain'] ?? '');

if ($key === '' || $domain === '') {
    ls_json(['ok' => false, 'code' => 'bad_request', 'message' => 'license_key and domain required'], 400);
}

$result = ls_verify($key, $domain);
$code = !empty($result['ok']) ? 200 : 403;
ls_json($result, $code);
