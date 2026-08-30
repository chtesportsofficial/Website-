<?php
// get-deposit-history.php
// Returns a user's recent deposit requests (any status) so the wallet page
// can show real history instead of a static "No transactions yet." — this
// includes pending and rejected requests too, not just approved ones.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php'; // must expose $conn (mysqli)
require_once __DIR__ . '/admin-auth.php'; // for verify_user_token()
$conn->set_charset('utf8mb4');

function respond($success, $data = []) {
    echo json_encode(array_merge(['success' => $success], $data));
    exit();
}

// SECURITY: supabase_uid must NEVER be taken from the client — that would
// let anyone read another user's deposit history just by editing the
// request body. Instead we verify the Supabase access_token server-side
// and use the uid Supabase itself returns for that token.
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$access_token = isset($input['access_token']) ? trim($input['access_token']) : '';

$supabase_uid = verify_user_token($access_token);
if (!$supabase_uid) {
    respond(false, ['message' => 'Not logged in or session expired. Please sign in again.']);
}

$lookup = $conn->prepare("SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1");
$lookup->bind_param('s', $supabase_uid);
$lookup->execute();
$walletUser = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$walletUser) {
    respond(false, ['message' => 'Wallet user not found for this account']);
}

$user_id = (int)$walletUser['id'];

$stmt = $conn->prepare(
    "SELECT id, method, amount, status, trx_id, admin_note, created_at, reviewed_at
     FROM wallet_deposit_requests
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 20"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();

respond(true, ['history' => $history]);
$conn->close();
