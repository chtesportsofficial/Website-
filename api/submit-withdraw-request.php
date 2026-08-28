<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

require_once "../db.php";

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$amount = isset($data['amount']) ? (float)$data['amount'] : 0.0;
$method = isset($data['method']) ? trim($data['method']) : '';
$accountNumber = isset($data['account_number']) ? trim($data['account_number']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if ($amount < 50) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Minimum withdraw is 50 BDT']);
    exit;
}
if (!in_array($method, ['Bkash', 'Nagad'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit;
}
if (!preg_match('/^01[0-9]{9}$/', $accountNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid 11-digit account number']);
    exit;
}

/* Verify the real Supabase session and get the real user id. */
$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$authResponse = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$authUser = json_decode($authResponse, true);
if ($authResponse === false || $authCode !== 200 || empty($authUser['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}

$verifiedUid = $authUser['id'];
$email = isset($authUser['email']) ? trim($authUser['email']) : '';

$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    /* Lock wallet so two withdrawal requests cannot spend the same funds. */
    $stmt = $conn->prepare(
        "SELECT id, email, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users
         WHERE supabase_uid = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $verifiedUid);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        throw new Exception('Wallet not found for this account');
    }

    $withdrawable = (float)$wallet['withdrawable_balance'];
    if ($withdrawable < $amount) {
        throw new Exception(
            'Insufficient withdrawable balance. Available: ৳' . $withdrawable
        );
    }

    $newWithdrawable = $withdrawable - $amount;
    $newNonWithdrawable = (float)$wallet['non_withdrawable_balance'];
    $newBalance = $newWithdrawable + $newNonWithdrawable;

    /* Reserve the money immediately. It is refunded automatically if an admin rejects the request. */
    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET withdrawable_balance = ?,
             balance = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ddi', $newWithdrawable, $newBalance, $wallet['id']);
    $stmt->execute();
    $stmt->close();

    $walletEmail = $wallet['email'] ?: $email;
    $stmt = $conn->prepare(
        "INSERT INTO wallet_withdraw_requests
         (user_id, email, amount, method, account_number, status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->bind_param(
        'ssdss',
        $verifiedUid,
        $walletEmail,
        $amount,
        $method,
        $accountNumber
    );
    $stmt->execute();
    $requestId = $stmt->insert_id;
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Withdrawal request submitted successfully. Your amount is reserved until admin review.',
        'request_id' => (int)$requestId,
        'balance' => $newBalance,
        'withdrawable_balance' => $newWithdrawable,
        'non_withdrawable_balance' => $newNonWithdrawable
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
