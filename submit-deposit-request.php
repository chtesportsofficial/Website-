<?php
// submit-deposit-request.php
// User submits a manual bKash/Nagad deposit claim (sender number + trx_id + amount).
// Row goes into wallet_deposit_requests as 'pending' for admin to manually approve.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php'; // must expose $conn (mysqli)

function respond($status, $data = []) {
    echo json_encode(array_merge(['status' => $status], $data));
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST; // fallback if sent as form-data instead of JSON
}

$user_id       = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$email         = isset($input['email']) ? trim($input['email']) : '';
$method        = isset($input['method']) ? strtolower(trim($input['method'])) : ''; // 'bkash' or 'nagad'
$sender_number = isset($input['sender_number']) ? trim($input['sender_number']) : '';
$trx_id        = isset($input['trx_id']) ? trim($input['trx_id']) : '';
$amount        = isset($input['amount']) ? floatval($input['amount']) : 0;

// --- Basic validation ---
if ($user_id <= 0) {
    respond(false, ['message' => 'Missing or invalid user_id']);
}
if (!in_array($method, ['bkash', 'nagad'])) {
    respond(false, ['message' => 'method must be bkash or nagad']);
}
if ($sender_number === '' || !preg_match('/^01[0-9]{9}$/', $sender_number)) {
    respond(false, ['message' => 'Invalid sender_number format (expected 01XXXXXXXXX)']);
}
if ($trx_id === '' || strlen($trx_id) < 6) {
    respond(false, ['message' => 'Invalid trx_id']);
}
if ($amount <= 0) {
    respond(false, ['message' => 'Amount must be greater than 0']);
}

// --- Prevent duplicate submission of the same trx_id ---
$check = $conn->prepare("SELECT id, status FROM wallet_deposit_requests WHERE trx_id = ? LIMIT 1");
$check->bind_param('s', $trx_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    respond(false, [
        'message' => 'This Transaction ID has already been submitted (status: ' . $existing['status'] . ')'
    ]);
}

// --- Insert the pending request ---
$stmt = $conn->prepare(
    "INSERT INTO wallet_deposit_requests
        (user_id, email, method, sender_number, trx_id, amount, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
);
$stmt->bind_param('isssd', $user_id, $email, $method, $sender_number, $trx_id, $amount);

if ($stmt->execute()) {
    respond(true, [
        'message'    => 'Deposit request submitted. It will be reviewed by an admin shortly.',
        'request_id' => $stmt->insert_id
    ]);
} else {
    respond(false, ['message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
