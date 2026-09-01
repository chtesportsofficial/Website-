<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../admin-auth.php"; // exposes verify_user_token($access_token) -> supabase_uid|null

/*
|--------------------------------------------------------------------------
| Why this changed from GET ?uid=... to POST { access_token }
|--------------------------------------------------------------------------
| Two problems with the old GET ?uid=... version:
| 1. IDOR: it trusted whatever uid the client sent — anyone could read
|    anyone else's balance by swapping the uid in the URL. Same class of
|    bug fixed on submit-deposit-request.php / get-deposit-history.php
|    (see Security Hardening report) — this endpoint was missed then.
| 2. Some mobile ad-blockers / privacy DNS filters block fetch()/XHR
|    requests whose URL contains "uid=" (a very common analytics/tracking
|    parameter name), even though they don't block a normal page
|    navigation to the same URL. That's exactly why this endpoint failed
|    silently (status 0) only for the in-app balance fetch, only via
|    JS, while sync-wallet.php / get-wallet-history.php (which already
|    send their uid/token in the POST body, not the URL) worked fine.
| Moving to POST + a verified access_token fixes both at once.
*/

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = isset($input['access_token']) ? trim($input['access_token']) : '';

$uid = verify_user_token($access_token);

if (!$uid) {
    echo json_encode([
        "success" => false,
        "message" => "Not logged in or session expired. Please sign in again."
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id, balance, withdrawable_balance, non_withdrawable_balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Database query error"
    ]);
    exit;
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$stmt->bind_result($user_id, $balance, $withdrawableBalance, $nonWithdrawableBalance);

if ($stmt->fetch()) {
    echo json_encode([
        "success" => true,
        "user_id" => $user_id,
        'balance' => (float)$balance,
        'withdrawable_balance' => (float)$withdrawableBalance,
        'non_withdrawable_balance' => (float)$nonWithdrawableBalance
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "User not found",
        "balance" => 0
    ]);
}

$stmt->close();
?>
