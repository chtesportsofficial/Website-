<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$targetUserId = isset($data['user_id']) ? trim($data['user_id']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

if ($targetUserId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'user_id required']);
    exit;
}

// ---- Verify the requesting admin with Supabase ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json'
    ]
]);
$supabaseResponse = curl_exec($ch);

if ($supabaseResponse === false) {
    curl_close($ch);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not verify user']);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User verification failed']);
    exit;
}

$requester = json_decode($supabaseResponse, true);
if (!is_array($requester) || empty($requester['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user data from Supabase']);
    exit;
}

$requesterId = $requester['id'];

// ---- Check admin/owner status ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?id=eq.' . urlencode($requesterId) . '&select=is_admin,is_owner');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$profileResponse = curl_exec($ch);
$profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($profileResponse === false || $profileHttpCode < 200 || $profileHttpCode >= 300) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not verify admin status']);
    exit;
}

$profileRows = json_decode($profileResponse, true);
$profile = (is_array($profileRows) && count($profileRows) > 0) ? $profileRows[0] : null;
$isAdmin = $profile && (!empty($profile['is_admin']) || !empty($profile['is_owner']));

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// ---- Fetch target user's profile from Supabase ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?id=eq.' . urlencode($targetUserId) . '&select=id,email,is_admin,is_owner');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$targetProfileResponse = curl_exec($ch);
$targetProfileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$targetProfile = null;
if ($targetProfileResponse !== false && $targetProfileHttpCode >= 200 && $targetProfileHttpCode < 300) {
    $rows = json_decode($targetProfileResponse, true);
    $targetProfile = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
}

if (!$targetProfile) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$targetEmail = $targetProfile['email'];

// ---- Fetch wallet balance (wallet_users is keyed by email) ----
$conn->set_charset('utf8mb4');

$balance = null;
$stmt = $conn->prepare("SELECT balance FROM wallet_users WHERE email = ?");
$stmt->bind_param('s', $targetEmail);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $balance = (float)$row['balance'];
}
$stmt->close();

// ---- Fetch this user's deposit request history ----
$stmt = $conn->prepare(
    "SELECT id, method, sender_number, trx_id, amount, status, admin_note, created_at, reviewed_at
     FROM wallet_deposit_requests
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('s', $targetUserId);
$stmt->execute();
$result = $stmt->get_result();

$deposits = [];
while ($row = $result->fetch_assoc()) {
    $row['amount'] = (float)$row['amount'];
    $deposits[] = $row;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $targetProfile['id'],
        'email' => $targetEmail,
        'is_admin' => !empty($targetProfile['is_admin']),
        'is_owner' => !empty($targetProfile['is_owner']),
        'balance' => $balance
    ],
    'deposits' => $deposits
]);

exit;
