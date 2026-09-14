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

/* Same category/direction normalization as get-wallet-history.php (the
   endpoint the user's own wallet page calls), so the admin panel shows
   the exact same unified deposit+withdraw+prize+adjustment history. */
function normalizeHistoryType($type) {
    $type = strtolower(trim((string) $type));
    if ($type === 'deposit') return 'deposit';
    if ($type === 'prize')   return 'prize';
    if ($type === 'withdraw') return 'withdraw';
    return 'other';
}
function historyDirectionFor($type) {
    $type = strtolower(trim((string) $type));
    if ($type === 'debit') return 'debit';
    return 'credit';
}

// ---- Fetch wallet balance + unified history (wallet_users is keyed by email) ----
$balance = null;
$withdrawableBalance = 0.0;
$nonWithdrawableBalance = 0.0;
$walletUserId = null;
$history = [];
try {
    $stmt = $conn->prepare(
        "SELECT id, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users WHERE email = ?"
    );
    $stmt->execute([$targetEmail]);
    if ($row = $stmt->fetch()) {
        $walletUserId = (int)$row['id'];
        $withdrawableBalance = (float)$row['withdrawable_balance'];
        $nonWithdrawableBalance = (float)$row['non_withdrawable_balance'];
        $balance = $withdrawableBalance + $nonWithdrawableBalance;
    }

    // ---- Unified wallet history — same 3-source merge as get-wallet-history.php ----
    // wallet_transactions / wallet_deposit_requests are keyed by the internal
    // numeric wallet_users.id ($walletUserId); wallet_withdraw_requests is
    // keyed by the raw Supabase UID ($targetUserId, varchar column) — see
    // get-wallet-history.php's comments for why these two differ.
    // Only run this if we found a wallet row.
    if ($walletUserId !== null) {

        // 1) Completed ledger entries: deposits (approved), prizes, and
        //    manual admin balance adjustments — NOT withdraws (see below).
        $stmt = $conn->prepare(
            "SELECT type, amount, description, status, created_at
             FROM wallet_transactions
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$walletUserId]);
        while ($r = $stmt->fetch()) {
            $history[] = [
                'category'   => normalizeHistoryType($r['type']),
                'direction'  => historyDirectionFor($r['type']),
                'amount'     => (float)$r['amount'],
                'method'     => $r['description'] ?: ucfirst($r['type']),
                'status'     => $r['status'] ?: 'approved',
                'created_at' => $r['created_at'],
            ];
        }

        // 2) Pending / rejected deposit requests (approved ones already
        //    appear above via wallet_transactions, so skip 'approved' here
        //    to avoid duplicates). Extra admin-useful fields kept here.
        $stmt = $conn->prepare(
            "SELECT method, sender_number, trx_id, amount, status, admin_note, created_at
             FROM wallet_deposit_requests
             WHERE user_id = ? AND status IN ('pending','rejected')
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$walletUserId]);
        while ($r = $stmt->fetch()) {
            $history[] = [
                'category'      => 'deposit',
                'direction'     => 'credit',
                'amount'        => (float)$r['amount'],
                'method'        => $r['method'],
                'status'        => $r['status'],
                'created_at'    => $r['created_at'],
                'sender_number' => $r['sender_number'],
                'trx_id'        => $r['trx_id'],
                'admin_note'    => $r['admin_note'],
            ];
        }

        // 3) ALL withdraw requests, every status — this table (not
        //    wallet_transactions) is the only source of truth for withdraws,
        //    at every status, since approving one doesn't write a new
        //    wallet_transactions row (balance is reserved/deducted at
        //    submission time already). Keyed by the raw Supabase UID.
        $stmt = $conn->prepare(
            "SELECT amount, method, status, created_at
             FROM wallet_withdraw_requests
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50"
        );
        $stmt->execute([$targetUserId]);
        while ($r = $stmt->fetch()) {
            $history[] = [
                'category'   => 'withdraw',
                'direction'  => 'debit',
                'amount'     => (float)$r['amount'],
                'method'     => $r['method'],
                'status'     => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }

        usort($history, function ($a, $b) {
            return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        });
        $history = array_slice($history, 0, 50);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed', 'error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $targetProfile['id'],
        'email' => $targetEmail,
        'is_admin' => !empty($targetProfile['is_admin']),
        'is_owner' => !empty($targetProfile['is_owner']),
        'balance' => $balance,
        'withdrawable_balance' => $withdrawableBalance,
        'non_withdrawable_balance' => $nonWithdrawableBalance
    ],
    'history' => $history
]);

exit;
