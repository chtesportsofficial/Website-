<?php
// CHTEO wallet balance endpoint.
// Authenticates the real Supabase session, then reads the matching Postgres wallet row.
// Migrated from MySQL/mysqli to Supabase Postgres/PDO.

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

function wallet_cors() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
    header('Access-Control-Max-Age: 86400');
}

wallet_cors();
header('Content-Type: application/json; charset=utf-8');

function json_out($data, $status = 200) {
    wallet_cors();
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('get-balance.php: FATAL: ' . $e['message'] . ' in ' . $e['file'] . ' on line ' . $e['line']);
        while (ob_get_level() > 0) ob_end_clean();
        wallet_cors();
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success'=>false,'message'=>'Wallet API server error']);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_out(['success'=>true]);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../supabase-config.php';

$token = '';

if (!empty($_POST['access_token'])) {
    $token = trim((string)$_POST['access_token']);
}

// Keep JSON-body compatibility for existing callers.
if (!$token) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (is_array($input) && !empty($input['access_token'])) {
        $token = trim((string)$input['access_token']);
    }
}

if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $token = trim($m[1]);
    }
}

if (!$token) {
    json_out(['success'=>false,'message'=>'Access token missing'], 401);
}

// Verify the Supabase access token with the cURL extension installed by Dockerfile.
$ch = curl_init(SUPABASE_URL . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]
]);

$resp = curl_exec($ch);
$curlError = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $status !== 200) {
    error_log('get-balance.php: Supabase auth failed. HTTP=' . $status . ' curl=' . $curlError);
    json_out(['success'=>false,'message'=>'Invalid or expired session'], 401);
}

$user = json_decode((string)$resp, true);

if (!is_array($user) || empty($user['id'])) {
    json_out(['success'=>false,'message'=>'Invalid or expired session'], 401);
}

$uid = (string)$user['id'];

try {
    $stmt = $conn->prepare(
        'SELECT id, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users
         WHERE supabase_uid = :uid
         LIMIT 1'
    );
    $stmt->execute(['uid' => $uid]);
    $row = $stmt->fetch();

    if ($row) {
        $out = [
            'success' => true,
            'user_id' => (int)$row['id'],
            'balance' => (float)$row['balance'],
            'withdrawable_balance' => (float)$row['withdrawable_balance'],
            'non_withdrawable_balance' => (float)$row['non_withdrawable_balance']
        ];
    } else {
        $out = [
            'success'=>false,
            'message'=>'Wallet user not found',
            'balance'=>0,
            'withdrawable_balance'=>0,
            'non_withdrawable_balance'=>0
        ];
    }
} catch (PDOException $e) {
    error_log('get-balance.php: query failed: ' . $e->getMessage());
    json_out(['success'=>false,'message'=>'Database query failed'], 500);
}

json_out($out);
