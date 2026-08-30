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
$targetUserId = isset($data['user_id']) ? trim($data['user_id']) : '';
$targetEmail  = isset($data['email']) ? trim($data['email']) : '';
$amount       = isset($data['amount']) ? (float)$data['amount'] : 0.0;
$type         = isset($data['type']) ? trim($data['type']) : '';
$balanceType = isset($data['balance_type']) ? trim($data['balance_type']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

if ($targetUserId === '' || $targetEmail === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'user_id and email required']);
    exit;
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
    exit;
}

if (!in_array($type, ['add', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "type must be 'add' or 'remove'"]);
    exit;
}

if (!in_array($balanceType, ['withdrawable', 'non_withdrawable'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "balance_type must be 'withdrawable' or 'non_withdrawable'"]);
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
$adminEmail  = isset($requester['email']) ? trim($requester['email']) : '';

// ---- Check admin/owner status from the profiles table (source of truth) ----
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

// ---- Process the balance adjustment inside a transaction ----
$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    // Lock the target user's wallet row so concurrent adjustments don't clash.
    $stmt = $conn->prepare(
        "SELECT id, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users WHERE email = ? FOR UPDATE"
    );
    $stmt->bind_param('s', $targetEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $walletRow = $result->fetch_assoc();
    $stmt->close();

    if (!$walletRow) {
        throw new Exception('Wallet account not found for this user');
    }

    $walletUserId = (int)$walletRow['id'];
    $currentWithdrawable = (float)$walletRow['withdrawable_balance'];
    $currentNonWithdrawable = (float)$walletRow['non_withdrawable_balance'];
    $currentBalance = $currentWithdrawable + $currentNonWithdrawable;

    $currentTypeBalance = $balanceType === 'withdrawable'
        ? $currentWithdrawable
        : $currentNonWithdrawable;

    if ($type === 'remove' && $currentTypeBalance < $amount) {
        throw new Exception(
            'Insufficient ' .
            ($balanceType === 'withdrawable' ? 'withdrawable' : 'non-withdrawable') .
            ' balance'
        );
    }

    $column = $balanceType === 'withdrawable'
        ? 'withdrawable_balance'
        : 'non_withdrawable_balance';

    $delta = $type === 'add' ? $amount : -$amount;

    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET $column = $column + ?,
             balance = withdrawable_balance + non_withdrawable_balance
         WHERE email = ?"
    );
    $stmt->bind_param('ds', $delta, $targetEmail);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        throw new Exception('Failed to update balance');
    }
    $stmt->close();

    // ---- Log the adjustment in the audit table ----
    $reason = 'Balance type: ' . $balanceType;
    $stmt = $conn->prepare(
        "INSERT INTO wallet_balance_adjustments (user_id, email, amount, type, reason, admin_email)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssdsss', $targetUserId, $targetEmail, $amount, $type, $reason, $adminEmail);
    $stmt->execute();
    $stmt->close();

    // ---- Fetch the new balance to return to the client ----
    $stmt = $conn->prepare(
        "SELECT balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users WHERE email = ?"
    );
    $stmt->bind_param('s', $targetEmail);
    $stmt->execute();
    $res = $stmt->get_result();
    $newBalance = null;
    $newWithdrawable = null;
    $newNonWithdrawable = null;
    if ($row = $res->fetch_assoc()) {
        $newBalance = (float)$row['balance'];
        $newWithdrawable = (float)$row['withdrawable_balance'];
        $newNonWithdrawable = (float)$row['non_withdrawable_balance'];
    }
    $stmt->close();

    // ---- Also log into wallet_transactions so this shows up in the user's
    //      Recent Transactions / wallet history (get-wallet-history.php).
    //      'debit' on remove -> shown as a minus and as "Adjustment"
    //      (normalizeType() falls through to 'other' for anything besides
    //      deposit/prize/withdraw, and directionFor() only treats 'debit'
    //      as a subtraction). 'adjustment' on add -> also "Adjustment", but
    //      shown as a plus since it isn't the literal string 'debit'.
    $txnType = $type === 'add' ? 'adjustment' : 'debit';
    $txnDescription = 'Admin balance adjustment (' .
        ($balanceType === 'withdrawable' ? 'Withdrawable' : 'Non-withdrawable') .
        ')';

    $stmt = $conn->prepare(
        "INSERT INTO wallet_transactions
            (user_id, type, amount, balance_before, balance_after, description, status)
         VALUES (?, ?, ?, ?, ?, ?, 'completed')"
    );
    $stmt->bind_param(
        'isddds',
        $walletUserId,
        $txnType,
        $amount,
        $currentBalance,
        $newBalance,
        $txnDescription
    );
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $type === 'add' ? 'Balance added successfully.' : 'Balance removed successfully.',
        'user_id' => $targetUserId,
        'email' => $targetEmail,
        'type' => $type,
        'amount' => $amount,
        'balance_type' => $balanceType,
        'new_balance' => $newBalance,
        'withdrawable_balance' => $newWithdrawable,
        'non_withdrawable_balance' => $newNonWithdrawable
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

exit;
