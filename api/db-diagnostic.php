<?php
// TEMPORARY CHTEO wallet diagnostic. Remove after fixing.
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function out($data, $status=200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') out(['success'=>true]);

// Safe endpoint test.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out([
        'success'=>true,
        'stage'=>'endpoint',
        'message'=>'CHTEO diagnostic endpoint is live',
        'service'=>'chteo-api',
        'php'=>PHP_VERSION
    ]);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
$token = is_array($body) ? trim((string)($body['access_token'] ?? '')) : '';

if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION']) &&
    preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $token = trim($m[1]);
}

if (!$token) out(['success'=>false,'stage'=>'auth','message'=>'Access token missing'],401);

require_once __DIR__ . '/../db.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    out(['success'=>false,'stage'=>'db','message'=>'Database connection failed'],500);
}

$supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : '';
if (!$supabaseUrl) out(['success'=>false,'stage'=>'supabase','message'=>'SUPABASE_URL is not configured'],500);

$anon = defined('SUPABASE_ANON_KEY') ? SUPABASE_ANON_KEY : '';
$ctx = stream_context_create([
    'http'=>[
        'method'=>'GET',
        'header'=>"Authorization: Bearer {$token}\r\napikey: {$anon}\r\n",
        'ignore_errors'=>true,
        'timeout'=>10
    ]
]);

$resp = @file_get_contents(rtrim($supabaseUrl,'/').'/auth/v1/user', false, $ctx);
$user = $resp !== false ? json_decode($resp, true) : null;

if (!is_array($user) || empty($user['id'])) {
    out(['success'=>false,'stage'=>'supabase','message'=>'Supabase rejected the access token'],401);
}

$uid = $user['id'];

$tableCheck = $conn->query("SHOW TABLES LIKE 'wallet_users'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    out(['success'=>false,'stage'=>'table','message'=>'wallet_users table not found']);
}

$required = ['id','supabase_uid','email','balance','withdrawable_balance','non_withdrawable_balance'];
$cols = [];
$r = $conn->query("SHOW COLUMNS FROM wallet_users");
if ($r) while ($row=$r->fetch_assoc()) $cols[]=$row['Field'];

$missing = array_values(array_diff($required,$cols));
if ($missing) {
    out([
        'success'=>false,
        'stage'=>'columns',
        'message'=>'Required wallet_users columns are missing',
        'missing_columns'=>$missing,
        'found_columns'=>$cols
    ]);
}

$stmt = $conn->prepare(
    "SELECT id,email,balance,withdrawable_balance,non_withdrawable_balance
     FROM wallet_users WHERE supabase_uid=? LIMIT 1"
);
if (!$stmt) out(['success'=>false,'stage'=>'query','message'=>'Could not prepare wallet query'],500);

$stmt->bind_param('s',$uid);
$stmt->execute();
$stmt->bind_result($id,$email,$balance,$withdrawable,$nonWithdrawable);

if (!$stmt->fetch()) {
    $stmt->close();
    out([
        'success'=>false,
        'stage'=>'user',
        'message'=>'No wallet_users row found for this Supabase user'
    ]);
}
$stmt->close();

out([
    'success'=>true,
    'stage'=>'complete',
    'message'=>'Wallet database check passed',
    'user'=>[
        'id'=>(int)$id,
        'email'=>$email,
        'balance'=>(float)$balance,
        'withdrawable_balance'=>(float)$withdrawable,
        'non_withdrawable_balance'=>(float)$nonWithdrawable
    ]
]);
?>