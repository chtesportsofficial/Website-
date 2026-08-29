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
$requestId   = isset($data['request_id']) ? (int)$data['request_id'] : 0;
$action      = isset($data['action']) ? trim($data['action']) : '';
$adminNote   = isset($data['admin_note']) ? trim($data['admin_note']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

if ($requestId <= 0 || !in_array($action, ['approve', 'decline'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid request_id and action (approve/decline) required']);
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
if (!is_array($user) || empty($user['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user data from Supabase']);
    exit;
}

$userId = $user['id'];
$adminEmail = isset($user['email']) ? trim($user['email']) : '';

// ---- Check admin/owner status from the profiles table (source of truth) ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?id=eq.' . urlencode($userId) . '&select=is_admin,is_owner');
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

// ---- Process the review inside a transaction ----
$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    // Lock the row so two admins can't process the same request at once.
    $stmt = $conn->prepare(
        "SELECT id, email, amount, status FROM wallet_deposit_requests WHERE id = ? FOR UPDATE"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $reqRow = $result->fetch_assoc();
    $stmt->close();

    if (!$reqRow) {
        throw new Exception('Deposit request not found');
    }

    if ($reqRow['status'] !== 'pending') {
        throw new Exception('This request was already reviewed');
    }

    $newStatus = $action === 'approve' ? 'approved' : 'declined';

    if ($action === 'approve') {
        $amount = (float)$reqRow['amount'];
        $depositEmail = $reqRow['email'];

        $stmt = $conn->prepare(
            "UPDATE wallet_users
             SET balance = balance + ?,
                 non_withdrawable_balance = non_withdrawable_balance + ?
             WHERE email = ?"
        );
        $stmt->bind_param('dds', $amount, $amount, $depositEmail);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $stmt->close();
            throw new Exception('Wallet account not found for this user');
        }
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "UPDATE wallet_deposit_requests
         SET status = ?, admin_note = ?, reviewed_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('ssi', $newStatus, $adminNote, $requestId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $action === 'approve' ? 'Deposit approved and balance updated.' : 'Deposit declined.',
        'request_id' => $requestId,
        'status' => $newStatus
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

exit;
