<?php
// submit-deposit-request.php
// User submits a manual bKash/Nagad deposit claim (sender number + trx_id + amount).
// Row goes into wallet_deposit_requests as 'pending' for admin to manually approve.
// A Telegram notification is sent right after the insert succeeds.
//
// NOTE: the frontend only has the Supabase auth UID in localStorage
// (`supabase_uid`), not the internal numeric wallet_users.id — so this
// endpoint takes supabase_uid and looks up the numeric id itself.

// Never print PHP warnings/errors into the response body — that breaks
// JSON parsing on the frontend. Log them instead (visible in Render logs).
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

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

function respond($status, $data = []) {
    echo json_encode(array_merge(['status' => $status], $data));
    exit();
}

// ---- Telegram notification helper ----
// TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID must be set as Render
// Environment Variables (Render dashboard -> your service -> Environment).
// Never hardcode the bot token in this file.
function notifyTelegram($message) {
    $botToken = getenv('TELEGRAM_BOT_TOKEN');
    $chatId   = getenv('TELEGRAM_CHAT_ID');

    if (!$botToken || !$chatId) {
        error_log('notifyTelegram: TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID not set — skipping notification');
        return;
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // don't let a slow Telegram API stall the submission
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('notifyTelegram: curl error: ' . curl_error($ch));
    }
    curl_close($ch);
}

// Catch any fatal error (e.g. calling a method on a failed prepare()) and
// still return valid JSON instead of an HTML error page.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => false, 'message' => 'Server error, please try again.']);
    }
});

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST; // fallback if sent as form-data instead of JSON
}

// SECURITY: supabase_uid must NEVER be taken from the client — that would
// let anyone submit a deposit request under someone else's account just by
// editing the request body. Instead we verify the Supabase access_token
// server-side and use the uid Supabase itself returns for that token.
$access_token  = isset($input['access_token']) ? trim($input['access_token']) : '';
$method        = isset($input['method']) ? strtolower(trim($input['method'])) : ''; // 'bkash' or 'nagad'
$sender_number = isset($input['sender_number']) ? trim($input['sender_number']) : '';
$trx_id        = isset($input['trx_id']) ? trim($input['trx_id']) : '';
$amount        = isset($input['amount']) ? floatval($input['amount']) : 0;

$supabase_uid = verify_user_token($access_token);
if (!$supabase_uid) {
    respond(false, ['message' => 'Not logged in or session expired. Please sign in again.']);
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

// --- Resolve supabase_uid -> internal numeric wallet_users.id ---
$lookup = $conn->prepare("SELECT id, email FROM wallet_users WHERE supabase_uid = ? LIMIT 1");
if (!$lookup) {
    error_log('submit-deposit-request.php prepare failed: ' . $conn->error);
    respond(false, ['message' => 'Server error (lookup query failed). Contact admin.']);
}
$lookup->bind_param('s', $supabase_uid);
$lookup->execute();
$walletUser = $lookup->get_result()->fetch_assoc();
$lookup->close();

if (!$walletUser) {
    respond(false, ['message' => 'Wallet user not found for this account']);
}

$user_id = (int)$walletUser['id'];
$email   = $walletUser['email'];

// DB enum is ('Bkash','Nagad') — capitalized — but the frontend sends
// lowercase 'bkash'/'nagad', so convert before inserting.
$method_db = ucfirst($method);

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
$stmt->bind_param('issssd', $user_id, $email, $method_db, $sender_number, $trx_id, $amount);

if ($stmt->execute()) {
    $request_id = $stmt->insert_id;

    // Notify only after the insert has actually succeeded, so a Telegram
    // hiccup can never block or falsely fail a real submission.
    // Server time (Asia/Dhaka) is used for the timestamp, not client time.
    $dhaka_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    notifyTelegram(
        "🟡 <b>New Deposit Request</b>\n"
        . "User: " . htmlspecialchars($email) . "\n"
        . "UID: <code>" . htmlspecialchars($supabase_uid) . "</code>\n"
        . "Method: " . htmlspecialchars($method_db) . "\n"
        . "Sender: " . htmlspecialchars($sender_number) . "\n"
        . "Amount: ৳" . number_format($amount, 2) . "\n"
        . "Trx ID: <code>" . htmlspecialchars($trx_id) . "</code>\n"
        . "Time: " . $dhaka_time->format('d M Y, h:i A')
    );

    respond(true, [
        'message'    => 'Deposit request submitted. It will be reviewed by an admin shortly.',
        'request_id' => $request_id
    ]);
} else {
    respond(false, ['message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
