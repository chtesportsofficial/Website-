<?php
// Start buffering IMMEDIATELY, before anything else — including the
// require_once calls below — can print a single stray byte. If any
// included file (db.php, admin-auth.php, supabase-config.php) has a
// BOM, a blank line before "<?php", a trailing blank line after "?>",
// or an accidental warning/notice, that output would normally get
// prepended/appended to the JSON body and corrupt the HTTP response
// at the framing level — which browsers' fetch() rejects outright as
// a network error (exactly "TypeError: Failed to fetch"), even though
// the server itself logs a 200. ob_start()+ob_end_clean() guarantees
// the client only ever receives exactly the JSON we intend to send.
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

require_once "../db.php";
require_once "../admin-auth.php"; // exposes verify_user_token($access_token) -> supabase_uid|null

/*
|--------------------------------------------------------------------------
| Why POST { access_token } instead of GET ?uid=...
|--------------------------------------------------------------------------
| 1. IDOR: the old version trusted whatever uid the client sent — anyone
|    could read anyone else's balance by swapping the uid in the URL.
| 2. A verified access_token (checked against Supabase, same pattern as
|    submit-deposit-request.php / get-deposit-history.php) is the only
|    way to know the request is really from that user.
*/

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = isset($input['access_token']) ? trim($input['access_token']) : '';

$uid = verify_user_token($access_token);

if (!$uid) {
    $out = json_encode([
        "success" => false,
        "message" => "Not logged in or session expired. Please sign in again."
    ]);
    ob_end_clean();
    echo $out;
    exit;
}

$stmt = $conn->prepare("SELECT id, balance, withdrawable_balance, non_withdrawable_balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1");

if (!$stmt) {
    $out = json_encode([
        "success" => false,
        "message" => "Database query error"
    ]);
    ob_end_clean();
    echo $out;
    exit;
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$stmt->bind_result($user_id, $balance, $withdrawableBalance, $nonWithdrawableBalance);

if ($stmt->fetch()) {
    $out = json_encode([
        "success" => true,
        "user_id" => $user_id,
        'balance' => (float)$balance,
        'withdrawable_balance' => (float)$withdrawableBalance,
        'non_withdrawable_balance' => (float)$nonWithdrawableBalance
    ]);
} else {
    $out = json_encode([
        "success" => false,
        "message" => "User not found",
        "balance" => 0
    ]);
}

$stmt->close();

// Discard any stray output that snuck in from included files, then
// send exactly (and only) the clean JSON we built above.
ob_end_clean();
echo $out;
