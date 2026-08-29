<?php
/**
 * get-wallet-history.php
 *
 * Returns a UNIFIED transaction history for the logged-in user:
 * deposit + withdraw + prize (and any future type) all in one list.
 *
 * Sources merged:
 *  1. wallet_transactions   -> completed/ledger entries (approved deposits,
 *                              admin debits/credits, and future prize credits)
 *  2. wallet_deposit_requests -> pending/rejected deposits not yet in the ledger
 *  3. wallet_withdraw_requests -> pending/rejected withdraws not yet in the ledger
 *
 * IMPORTANT: wallet_withdraw_requests.user_id is varchar(255) (stores the raw
 * Supabase UID), UNLIKE wallet_deposit_requests/wallet_transactions.user_id
 * which store the integer wallet_users.id. This file queries each table with
 * the correct id for that reason. If you later rebuild the withdraw request
 * flow to store the integer wallet_users.id instead, update the query below
 * (marked WITHDRAW ID NOTE) to match.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php'; // must expose a connected mysqli instance as $mysqli

function respond($arr) {
    echo json_encode($arr);
    exit;
}

function normalizeType($type) {
    $type = strtolower(trim((string) $type));
    if ($type === 'deposit') return 'deposit';
    if ($type === 'prize')   return 'prize';
    // 'debit' currently covers both manual admin adjustments and (once built)
    // approved withdraws logged the same way the deposit flow does.
    if ($type === 'debit' || $type === 'withdraw') return 'withdraw';
    return $type ?: 'other';
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
$accessToken = $body['access_token'] ?? '';

if (!$accessToken) {
    respond(['success' => false, 'message' => 'Missing access token']);
}

// --- Verify the Supabase access token and get the Supabase user id ---
$supabaseUrl  = getenv('SUPABASE_URL');       // e.g. https://xxxx.supabase.co
$supabaseAnon = getenv('SUPABASE_ANON_KEY');  // anon/public key

$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'apikey: ' . $supabaseAnon,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    respond(['success' => false, 'message' => 'Invalid or expired session']);
}

$supabaseUser = json_decode($resp, true);
$supabaseUid  = $supabaseUser['id'] ?? null;

if (!$supabaseUid) {
    respond(['success' => false, 'message' => 'Could not resolve user']);
}

// --- Map supabase_uid -> wallet_users.id (integer id used by most tables) ---
$stmt = $mysqli->prepare("SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1");
$stmt->bind_param('s', $supabaseUid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    respond(['success' => false, 'message' => 'Wallet user not found']);
}
$userId = (int) $row['id'];

$history = [];

// 1) Completed ledger entries: deposits, debits/withdraws, prizes, adjustments
$stmt = $mysqli->prepare(
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
        'amount'     => $r['amount'],
        'method'     => $r['description'] ?: ucfirst($r['type']),
        'status'     => $r['status'] ?: 'approved',
        'created_at' => $r['created_at'],
    ];
}
$stmt->close();

// 2) Pending / rejected deposit requests (approved ones already appear above
//    via wallet_transactions, so we skip 'approved' here to avoid duplicates)
$stmt = $mysqli->prepare(
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
        'amount'     => $r['amount'],
        'method'     => $r['method'],
        'status'     => $r['status'],
        'created_at' => $r['created_at'],
    ];
}
$stmt->close();

// 3) Pending / rejected withdraw requests
//    WITHDRAW ID NOTE: this table's user_id column is varchar(255) and, as far
//    as we've confirmed, stores the raw Supabase UID directly (not the
//    wallet_users.id integer like the other tables) - so we filter by
//    $supabaseUid here, not $userId.
$stmt = $mysqli->prepare(
    "SELECT amount, method, status, created_at
     FROM wallet_withdraw_requests
     WHERE user_id = ? AND status IN ('pending','rejected')
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('s', $supabaseUid);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $history[] = [
        'category'   => 'withdraw',
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
