<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
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
$requestId = isset($data['request_id']) ? (int)$data['request_id'] : 0;
$action = isset($data['action']) ? trim($data['action']) : '';
$adminNote = isset($data['admin_note']) ? trim($data['admin_note']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'request_id required']);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "action must be 'approve' or 'reject'"]);
    exit;
}

/* Verify requester. */
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

$requester = json_decode($authResponse, true);
if ($authResponse === false || $authCode !== 200 || empty($requester['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}

$requesterId = $requester['id'];
$adminEmail = isset($requester['email']) ? trim($requester['email']) : '';

/* Check admin/owner. */
$ch = curl_init(
    rtrim($supabaseUrl, '/') .
    '/rest/v1/profiles?id=eq.' . urlencode($requesterId) .
    '&select=is_admin,is_owner'
);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$profileResponse = curl_exec($ch);
$profileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rows = json_decode($profileResponse, true);
$profile = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
$isAdmin = $profile && (!empty($profile['is_admin']) || !empty($profile['is_owner']));

if ($profileResponse === false || $profileCode < 200 || $profileCode >= 300 || !$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT id, user_id, amount, status
         FROM wallet_withdraw_requests
         WHERE id = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new Exception('Withdrawal request not found');
    }
    if ($request['status'] !== 'pending') {
        throw new Exception('This withdrawal request has already been reviewed');
    }

    $amount = (float)$request['amount'];
    $userId = $request['user_id'];

    $stmt = $conn->prepare(
        "SELECT id, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users
         WHERE supabase_uid = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        throw new Exception('Wallet not found for this user');
    }

    $newWithdrawable = (float)$wallet['withdrawable_balance'];
    $newNonWithdrawable = (float)$wallet['non_withdrawable_balance'];

    /* The amount was reserved when the request was submitted.
       Approval keeps it deducted; rejection returns it to withdrawable balance. */
    if ($action === 'reject') {
        $newWithdrawable += $amount;
        $newBalance = $newWithdrawable + $newNonWithdrawable;

        $stmt = $conn->prepare(
            "UPDATE wallet_users
             SET withdrawable_balance = ?, balance = ?
             WHERE id = ?"
        );
        $stmt->bind_param('ddi', $newWithdrawable, $newBalance, $wallet['id']);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "UPDATE wallet_withdraw_requests
         SET status = ?, admin_note = ?, reviewed_at = NOW()
         WHERE id = ?"
    );
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $stmt->bind_param('ssi', $status, $adminNote, $requestId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $action === 'approve'
            ? 'Withdrawal approved successfully.'
            : 'Withdrawal rejected and amount refunded.',
        'request_id' => $requestId,
        'status' => $status,
        'admin_email' => $adminEmail
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
