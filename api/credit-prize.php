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

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$credits = isset($data['credits']) && is_array($data['credits']) ? $data['credits'] : [];

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if (!count($credits)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No credits provided']);
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

/* Credit each winner. Prize money is withdrawable (unlike deposits).
   Also logs a 'prize' row into wallet_transactions so it shows up in
   the user's Recent Transactions / wallet history (get-wallet-history.php
   already recognizes type = 'prize' via normalizeType()). */
$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    $updateStmt = $conn->prepare(
        "UPDATE wallet_users
         SET withdrawable_balance = withdrawable_balance + ?,
             balance = balance + ?
         WHERE supabase_uid = ?"
    );
    $lookupStmt = $conn->prepare(
        "SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1"
    );
    $insertStmt = $conn->prepare(
        "INSERT INTO wallet_transactions
            (user_id, type, amount, description, status)
         VALUES (?, 'prize', ?, 'Tournament prize', 'completed')"
    );

    $credited = [];
    $skipped = [];

    foreach ($credits as $c) {
        $uid = isset($c['supabase_uid']) ? trim($c['supabase_uid']) : '';
        $amount = isset($c['amount']) ? (float)$c['amount'] : 0;

        if ($uid === '' || $amount <= 0) {
            $skipped[] = $c;
            continue;
        }

        $updateStmt->bind_param('dds', $amount, $amount, $uid);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            // Resolve the integer wallet_users.id for the transactions log
            // (wallet_transactions.user_id is int, NOT the supabase_uid string).
            $lookupStmt->bind_param('s', $uid);
            $lookupStmt->execute();
            $row = $lookupStmt->get_result()->fetch_assoc();

            if ($row) {
                $walletUserId = (int)$row['id'];
                $insertStmt->bind_param('id', $walletUserId, $amount);
                $insertStmt->execute();
            }

            $credited[] = ['supabase_uid' => $uid, 'amount' => $amount];
        } else {
            $skipped[] = $c; // no wallet_users row for this uid
        }
    }
    $updateStmt->close();
    $lookupStmt->close();
    $insertStmt->close();

    if (!count($credited)) {
        throw new Exception('No wallet accounts found for the given users');
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => count($credited) . ' winner(s) credited.',
        'credited' => $credited,
        'skipped' => $skipped
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
