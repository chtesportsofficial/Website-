<?php
// get-referral-history.php
// Returns the logged-in user's referral commission history — one row per
// approved deposit made by someone they referred — for the "Commission
// Details" table in refer-earn.html:
//   joining_date, team_name, phone, deposit_amount, commission_amount, status
//
// "phone" is sourced from wallet_deposit_requests.sender_number (the
// bKash/Nagad number the deposit was made from) via
// referral_commissions.deposit_request_id — wallet_users itself has no
// phone column. The frontend's existing maskPhone() JS helper masks it
// for display.
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
    error_log('get-referral-history.php prepare failed: ' . $conn->error);
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
| One row per commission earned, newest first
|--------------------------------------------------------------------------
| - joining_date  : wu.created_at        (referred member's own signup date,
|                    not the deposit date)
| - team_name     : wu.name
| - phone         : wdr.sender_number    (bKash/Nagad number from that
|                    specific deposit — no phone column exists on
|                    wallet_users itself)
| - status        : first-deposit (10%) commissions are withdrawable
|                    immediately; repeat (5%) commissions are held for
|                    6 months from created_at before they're withdrawable
|                    (see refer-Roadmap.md — no extra "hold" column needed,
|                    computed here from created_at)
*/
$stmt = $conn->prepare(
    "SELECT
        rc.created_at        AS commission_date,
        wu.created_at        AS joining_date,
        wu.name               AS team_name,
        wdr.sender_number     AS phone,
        rc.deposit_amount     AS deposit_amount,
        rc.commission_rate    AS commission_rate,
        rc.commission_amount  AS commission_amount,
        rc.is_first_deposit   AS is_first_deposit
     FROM referral_commissions rc
     JOIN wallet_users wu           ON wu.id = rc.referred_id
     LEFT JOIN wallet_deposit_requests wdr ON wdr.id = rc.deposit_request_id
     WHERE rc.referrer_id = ?
     ORDER BY rc.created_at DESC"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];

while ($row = $result->fetch_assoc()) {

    $isFirstDeposit = (bool)$row['is_first_deposit'];

    if ($isFirstDeposit) {
        $status = 'Completed';
    } else {
        $sixMonthsAgo = new DateTime('-6 months');
        $commissionDate = new DateTime($row['commission_date']);
        $status = ($commissionDate <= $sixMonthsAgo) ? 'Withdrawable' : 'Held (6mo)';
    }

    $history[] = [
        'joining_date'      => $row['joining_date'],
        'team_name'         => $row['team_name'] ?: 'N/A',
        'phone'             => $row['phone'] ?: null,
        'deposit_amount'    => (float)$row['deposit_amount'],
        'commission_rate'   => (int)$row['commission_rate'],
        'commission_amount' => (float)$row['commission_amount'],
        'status'            => $status
    ];
}

$stmt->close();

respond(true, [
    'history' => $history
]);

$conn->close();
