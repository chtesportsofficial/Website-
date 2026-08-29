<?php
/**
 * get-wallet-history.php
 *
 * Returns a UNIFIED transaction history for the logged-in user:
 * deposit + withdraw + prize (and any future type) all in one list.
 *
 * Sources merged:
 *  1. wallet_transactions      -> deposits (approved), prizes (future), and
 *                                 manual admin balance adjustments ('debit'
 *                                 type - shown as a generic "Adjustment",
 *                                 NOT as a withdraw - see normalizeType()).
 *  2. wallet_deposit_requests  -> pending/rejected deposits not yet in the
 *                                 ledger (approved ones are already covered
 *                                 by wallet_transactions).
 *  3. wallet_withdraw_requests -> ALL withdraw requests, every status. This
 *                                 table (not wallet_transactions) is the only
 *                                 source of truth for withdraws, because
 *                                 submit-withdraw-request.php reserves/deducts
 *                                 the balance immediately at submission time
 *                                 and approving a request does not currently
 *                                 write a new wallet_transactions row.
 *
 * IMPORTANT: wallet_withdraw_requests.user_id is varchar(255) and stores the
 * raw Supabase UID directly (confirmed from submit-withdraw-request.php:
 * `$userId = $user['id']` straight off the Supabase auth response) - UNLIKE
 * wallet_deposit_requests/wallet_transactions.user_id, which store the
 * integer wallet_users.id. This file queries each table with the correct id
 * for that reason.
 *
 * DB connection: db.php exposes the mysqli connection as $conn (matching
 * submit-withdraw-request.php), not $mysqli.
 *
 * Supabase URL/anon key are hardcoded below to match the rest of the
 * codebase's convention, since Render's env vars don't include
 * SUPABASE_URL / SUPABASE_ANON_KEY (only SUPABASE_SERVICE_KEY, used
 * elsewhere for admin-only verification).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php'; // must expose a connected mysqli instance as $conn

function respond($arr) {
    echo json_encode($arr);
    exit;
}

function normalizeType($type) {
    $type = strtolower(trim((string) $type));
    if ($type === 'deposit') return 'deposit';
    if ($type === 'prize')   return 'prize';
    // NOTE: 'debit' rows in wallet_transactions currently come from the
    // separate wallet_balance_adjustments admin tool, NOT from real user
    // withdraws (those live in wallet_withdraw_requests below, at every
    // status). So 'debit' is shown as a generic adjustment, not "Withdraw" -
    // relabel this if that ever changes.
    if ($type === 'withdraw') return 'withdraw';
    return 'other';
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
$accessToken = $body['access_token'] ?? '';

if (!$accessToken) {
    respond(['success' => false, 'message' => 'Missing access token']);
}

// --- Verify the Supabase access token and get the Supabase user id ---
// Same URL/key as submit-withdraw-request.php - Render has no SUPABASE_URL /
// SUPABASE_ANON_KEY env vars, so these are hardcoded to match that convention.
$supabaseUrl     = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
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
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    respond(['success' => false, 'message' => 'Invalid or expired session']);
}

$supabaseUser = json_decode($resp, true);
$supabaseUid  = $supabaseUser['id'] ?? null;

if (!$supabaseUid) {
    respond(['success' => false, 'message' => 'Could not resolve user']);
}

// --- Map supabase_uid -> wallet_users.id (integer id used by most tables) ---
$stmt = $conn->prepare("SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1");
$stmt->bind_param('s', $supabaseUid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    respond(['success' => false, 'message' => 'Wallet user not found']);
}
$userId = (int) $row['id'];

function directionFor($type) {
    // Real accounting direction, independent of display category - a 'debit'
    // wallet_transactions row (e.g. a tournament entry fee) must show as a
    // subtraction even though its display category is the generic
    // "Adjustment" bucket, not "Withdraw".
    $type = strtolower(trim((string) $type));
    if ($type === 'debit') return 'debit';
    return 'credit';
}

$history = [];

// 1) Completed ledger entries: deposits, prizes, and manual admin adjustments
//    (NOT withdraws - see normalizeType() note above)
$stmt = $conn->prepare(
    "SELECT type, amount, description, status, created_at
     FROM wallet_transactions
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $history[] = [
        'category'   => normalizeType($r['type']),
        'direction'  => directionFor($r['type']),
        'amount'     => $r['amount'],
        'method'     => $r['description'] ?: ucfirst($r['type']),
        'status'     => $r['status'] ?: 'approved',
        'created_at' => $r['created_at'],
    ];
}
$stmt->close();

// 2) Pending / rejected deposit requests (approved ones already appear above
//    via wallet_transactions, so we skip 'approved' here to avoid duplicates)
$stmt = $conn->prepare(
    "SELECT amount, method, status, created_at
     FROM wallet_deposit_requests
     WHERE user_id = ? AND status IN ('pending','rejected')
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $history[] = [
        'category'   => 'deposit',
        'direction'  => 'credit',
        'amount'     => $r['amount'],
        'method'     => $r['method'],
        'status'     => $r['status'],
        'created_at' => $r['created_at'],
    ];
}
$stmt->close();

// 3) ALL withdraw requests (pending/approved/rejected) - unlike deposits,
//    approving a withdraw does NOT currently write a new wallet_transactions
//    row (submit-withdraw-request.php already reserves/deducts the balance
//    at submit time), so wallet_withdraw_requests is the only source of
//    truth for withdraws at every status.
//    Its user_id column is varchar(255) storing the raw Supabase UID
//    (confirmed from submit-withdraw-request.php: `$userId = $user['id']`
//    straight from the Supabase auth response) - so filter by $supabaseUid.
$stmt = $conn->prepare(
    "SELECT amount, method, status, created_at
     FROM wallet_withdraw_requests
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('s', $supabaseUid);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $history[] = [
        'category'   => 'withdraw',
        'direction'  => 'debit',
        'amount'     => $r['amount'],
        'method'     => $r['method'],
        'status'     => $r['status'],
        'created_at' => $r['created_at'],
    ];
}
$stmt->close();

// Merge everything, sort by newest first, cap the list
usort($history, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$history = array_slice($history, 0, 30);

respond(['success' => true, 'history' => $history]);
