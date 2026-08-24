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
$conn->set_charset('utf8mb4');

function respond($success, $data = []) {
    echo json_encode(array_merge(['success' => $success], $data));
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$supabase_uid = isset($input['supabase_uid']) ? trim($input['supabase_uid']) : '';

if ($supabase_uid === '') {
    respond(false, ['message' => 'Missing supabase_uid']);
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
