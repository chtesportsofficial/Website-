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

$accessToken  = isset($data['access_token']) ? trim($data['access_token']) : '';
$method       = isset($data['method']) ? trim($data['method']) : '';
$senderNumber = isset($data['sender_number']) ? trim($data['sender_number']) : '';
$trxId        = isset($data['trx_id']) ? trim($data['trx_id']) : '';
$amount       = isset($data['amount']) ? (float)$data['amount'] : 0;

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

if (!in_array($method, ['Bkash', 'Nagad'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit;
}

if ($senderNumber === '' || $trxId === '' || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Number, Transaction ID and a valid amount are required']);
    exit;
}

// ---- Verify the user with Supabase ----
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

$user = json_decode($supabaseResponse, true);
if (!is_array($user) || empty($user['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user data from Supabase']);
    exit;
}

$email = trim($user['email']);

// ---- Find the matching wallet_users row ----
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare("SELECT id FROM wallet_users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($walletUserId);

if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Wallet account not found for this user']);
    exit;
}
$stmt->close();

// ---- Insert the pending deposit request ----
$stmt = $conn->prepare(
    "INSERT INTO wallet_deposit_requests
        (user_id, email, method, sender_number, trx_id, amount, status)
     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database prepare failed', 'error' => $conn->error]);
    exit;
}

$stmt->bind_param('issssd', $walletUserId, $email, $method, $senderNumber, $trxId, $amount);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save deposit request', 'error' => $error]);
    exit;
}

$requestId = (int)$stmt->insert_id;
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Deposit request submitted. It will be reviewed shortly.',
    'request_id' => $requestId
]);

exit;
