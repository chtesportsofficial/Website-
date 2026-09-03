<?php
// get-balance.php — return JSON only, even when a backend error occurs.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function json_out($data, $status = 200) {
    if (!headers_sent()) http_response_code($status);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success'=>false,'message'=>'Wallet API server error']);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') json_out(['success'=>true]);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../supabase-config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = $_GET;
} else {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) $input = [];
    // Also accept CORS-safelisted application/x-www-form-urlencoded requests.
    if (empty($input) && !empty($_POST)) {
        $input = $_POST;
    }
}

$token = '';
if (!empty($input['access_token'])) {
    $token = trim((string)$input['access_token']);
}
if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) $token = trim($m[1]);
}
if (!$token) json_out(['success'=>false,'message'=>'Access token missing'], 401);

// Verify the Supabase access token without relying on the PHP cURL extension.
$ctx = stream_context_create(['http' => [
    'method' => 'GET',
    'timeout' => 12,
    'ignore_errors' => true,
    'header' => "apikey: " . SUPABASE_ANON_KEY . "\r\nAuthorization: Bearer " . $token . "\r\nAccept: application/json\r\n"
]]);
$resp = @file_get_contents(SUPABASE_URL . '/auth/v1/user', false, $ctx);
$status = 0;
if (!empty($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
    }
}
$user = json_decode((string)$resp, true);
if ($status !== 200 || !is_array($user) || empty($user['id'])) {
    json_out(['success'=>false,'message'=>'Invalid or expired session'], 401);
}

$uid = (string)$user['id'];
$stmt = $conn->prepare('SELECT id, balance, withdrawable_balance, non_withdrawable_balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1');
if (!$stmt) json_out(['success'=>false,'message'=>'Database query error'], 500);
$stmt->bind_param('s', $uid);
if (!$stmt->execute()) {
    $stmt->close();
    json_out(['success'=>false,'message'=>'Database query failed'], 500);
}
$stmt->bind_result($user_id, $balance, $withdrawableBalance, $nonWithdrawableBalance);
if ($stmt->fetch()) {
    $out = [
        'success' => true,
        'user_id' => (int)$user_id,
        'balance' => (float)$balance,
        'withdrawable_balance' => (float)$withdrawableBalance,
        'non_withdrawable_balance' => (float)$nonWithdrawableBalance
    ];
} else {
    $out = ['success'=>false,'message'=>'Wallet user not found','balance'=>0,'withdrawable_balance'=>0,'non_withdrawable_balance'=>0];
}
$stmt->close();
json_out($out);
