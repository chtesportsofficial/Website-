<?php
// get-referral-stats.php
// Returns the logged-in user's referral summary stats for refer-earn.html:
//   - total_commissions   : lifetime sum of commission_amount earned as a referrer
//   - registered_members  : how many people signed up using this user's referral code
//   - total_deposit_amount: sum of the deposit amounts that generated those commissions
//   - invite_code         : this user's own referral code (Supabase profiles.user_number, "#N")
//
// SECURITY: identical pattern to submit-deposit-request.php — the caller's
// identity is taken from a verified Supabase access_token, never trusted
// from client-supplied input.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';         // exposes $conn (mysqli)
require_once __DIR__ . '/admin-auth.php'; // for verify_user_token()
$conn->set_charset('utf8mb4');

function respond($success, $data = []) {
    echo json_encode(array_merge(['success' => $success], $data));
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = trim($input['access_token'] ?? '');

$supabase_uid = verify_user_token($access_token);
if (!$supabase_uid) {
    respond(false, ['message' => 'Not logged in or session expired. Please sign in again.']);
}

// --- Resolve supabase_uid -> internal numeric wallet_users.id ---
$lookup = $conn->prepare("SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1");
if (!$lookup) {
    error_log('get-referral-stats.php prepare failed: ' . $conn->error);
    respond(false, ['message' => 'Server error (lookup query failed).']);
}
$lookup->bind_param('s', $supabase_uid);
$lookup->execute();
$walletUser = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$walletUser) {
    respond(false, ['message' => 'Wallet user not found for this account']);
}

$user_id = (int)$walletUser['id'];

/*
|--------------------------------------------------------------------------
| Registered members: everyone whose referred_by points to this user
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM wallet_users WHERE referred_by = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$registered_members = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

/*
|--------------------------------------------------------------------------
| Total commissions + total deposit amount that generated them
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(commission_amount), 0) AS total_commissions,
        COALESCE(SUM(deposit_amount), 0)    AS total_deposit_amount
     FROM referral_commissions
     WHERE referrer_id = ?"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$sums = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_commissions    = (float)($sums['total_commissions'] ?? 0);
$total_deposit_amount = (float)($sums['total_deposit_amount'] ?? 0);

/*
|--------------------------------------------------------------------------
| This user's own invite code — Supabase profiles.user_number, shown as
| "#N" (see api/sync-wallet.php's resolveReferrerWalletId() for the
| reverse lookup — this is the same convention, just in the other direction)
|--------------------------------------------------------------------------
*/
$invite_code = null;

$serviceKey = getenv('SUPABASE_SERVICE_KEY');
$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';

if ($serviceKey) {
    $ch = curl_init(
        $supabaseUrl . '/rest/v1/profiles?id=eq.' . urlencode($supabase_uid) . '&select=user_number'
    );
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp !== false && $httpCode === 200) {
        $rows = json_decode($resp, true);
        if (is_array($rows) && !empty($rows[0]['user_number'])) {
            $invite_code = '#' . $rows[0]['user_number'];
        }
    }
}

respond(true, [
    'total_commissions'     => $total_commissions,
    'registered_members'    => $registered_members,
    'total_deposit_amount'  => $total_deposit_amount,
    'invite_code'           => $invite_code
]);

$conn->close();
