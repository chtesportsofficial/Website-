<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

// ---- Telegram notification config (CHTEO Withdraw Alerts group) ----
$telegramBotToken = '8946675932:AAHxGR-v1JoGVDmpKJYnpqriKpF7swjSKkE';
$telegramChatId   = '-5433914490';

$MIN_WITHDRAW = 50.00;

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken   = isset($data['access_token']) ? trim($data['access_token']) : '';
$method        = isset($data['method']) ? trim($data['method']) : '';
$amount        = isset($data['amount']) ? (float)$data['amount'] : 0;
$accountNumber = isset($data['account_number']) ? trim($data['account_number']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if (!in_array($method, ['Bkash', 'Nagad'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a valid payment method']);
    exit;
}
if ($amount < $MIN_WITHDRAW) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Minimum withdraw is ' . $MIN_WITHDRAW . ' BDT']);
    exit;
}
if (!preg_match('/^01[0-9]{9}$/', $accountNumber)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid 11-digit number (01XXXXXXXXX)']);
    exit;
}

// ---- Verify the user with Supabase ----
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
$authResponse = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$user = json_decode($authResponse, true);
if ($authResponse === false || $authCode !== 200 || empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}

$userId = $user['id'];
$email  = isset($user['email']) ? trim($user['email']) : '';

// ---- Deduct from withdrawable_balance and insert the request, atomically ----
$conn->set_charset('utf8mb4');
$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT id, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users
         WHERE supabase_uid = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $wallet = $result->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        throw new Exception('Wallet account not found for this user');
    }

    $withdrawable = (float)$wallet['withdrawable_balance'];
    $nonWithdrawable = (float)$wallet['non_withdrawable_balance'];

    if ($withdrawable < $amount) {
        throw new Exception('Insufficient withdrawable balance');
    }

    $newWithdrawable = $withdrawable - $amount;
    $newBalance = $newWithdrawable + $nonWithdrawable;

    // Reserve the amount immediately — approval later keeps it deducted,
    // rejection (see admin-review-withdraw.php) refunds it back.
    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET withdrawable_balance = ?, balance = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ddi', $newWithdrawable, $newBalance, $wallet['id']);
    $stmt->execute();
    $stmt->close();

    $status = 'pending';
    $stmt = $conn->prepare(
        "INSERT INTO wallet_withdraw_requests
            (user_id, email, amount, method, account_number, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('ssdsss', $userId, $email, $amount, $method, $accountNumber, $status);
    $stmt->execute();
    $requestId = (int)$stmt->insert_id;
    $stmt->close();

    $conn->commit();

    // ---- Notify the admin Telegram group (CHTEO Withdraw Alerts) ----
    // Best-effort: if Telegram is slow/down, it must NOT break the
    // withdraw request response, so failures here are silently ignored.
    $telegramText =
        "🟠 New Withdraw Request\n" .
        "Email: " . $email . "\n" .
        "Method: " . $method . "\n" .
        "Account: " . $accountNumber . "\n" .
        "Amount: ৳" . number_format($amount, 2) . "\n" .
        "Request ID: " . $requestId . "\n" .
        "Time: " . date('d M Y, h:i A');

    $tgCh = curl_init("https://api.telegram.org/bot{$telegramBotToken}/sendMessage");
    curl_setopt_array($tgCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'chat_id' => $telegramChatId,
            'text'    => $telegramText
        ])
    ]);
    $tgResponse = curl_exec($tgCh);
    $tgError = curl_error($tgCh);
    curl_close($tgCh);
    error_log('Telegram withdraw notify response: ' . var_export($tgResponse, true));
    if ($tgError) {
        error_log('Telegram withdraw notify curl error: ' . $tgError);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Withdrawal request submitted successfully.',
        'request_id' => $requestId,
        'withdrawable_balance' => $newWithdrawable,
        'balance' => $newBalance
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
