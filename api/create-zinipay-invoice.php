<?php
// create-zinipay-invoice.php
// Replaces the manual deposit-request.php flow for ZiniPay auto-verified deposits.
// Flow: user submits only an amount -> we create a pending wallet_deposit_requests
// row -> call ZiniPay /v1/payment/create -> store the invoice_id on that row ->
// return payment_url so the frontend can redirect the user to pay.
// Actual crediting happens later in zinipay-webhook.php once ZiniPay confirms payment.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

// ---- ZiniPay config ----
// Set these two in Render's Environment Variables (do NOT hardcode the key here):
//   ZINIPAY_API_KEY   = your live Brand Key/API Key from dash.zinipay.com
//   ZINIPAY_WEBHOOK_URL = e.g. https://chteo-api.onrender.com/zinipay-webhook.php
$zinipayApiKey   = getenv('ZINIPAY_API_KEY') ?: '';
$zinipayBaseUrl  = 'https://api.zinipay.com';
$webhookUrl      = getenv('ZINIPAY_WEBHOOK_URL') ?: '';

// TODO: replace these with your real GitHub Pages URLs once you confirm them.
// redirect_url's domain must match the website domain registered on the ZiniPay brand.
$redirectUrlBase = 'https://chtesportsofficial.github.io/Website-/deposit-success.html';
$cancelUrlBase    = 'https://chtesportsofficial.github.io/Website-/deposit-cancel.html';

if ($zinipayApiKey === '' || $webhookUrl === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ZiniPay is not configured on the server yet']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$amount      = isset($data['amount']) ? (float)$data['amount'] : 0;

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid amount is required']);
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
        'Accept: application/json',
        'Content-Type: application/json'
    ]
]);
$supabaseResponse = curl_exec($ch);

if ($supabaseResponse === false) {
    curl_close($ch);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not verify user']);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User verification failed']);
    exit;
}

$user = json_decode($supabaseResponse, true);
if (!is_array($user) || empty($user['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user data from Supabase']);
    exit;
}

$email = trim($user['email']);

try {
    // ---- Find the matching wallet_users row ----
    $stmt = $conn->prepare("SELECT id FROM wallet_users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $walletUserRow = $stmt->fetch();

    if (!$walletUserRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Wallet account not found for this user']);
        exit;
    }
    $walletUserId = $walletUserRow['id'];

    // ---- Insert a pending deposit request first, so we have a request_id
    //      to tie back to the ZiniPay invoice via metadata ----
    // method/sender_number/trx_id are unknown until ZiniPay confirms the
    // payment, so they're placeholders for now and get filled in by
    // zinipay-webhook.php once verified.
    $stmt = $conn->prepare(
        "INSERT INTO wallet_deposit_requests
            (user_id, email, method, sender_number, trx_id, amount, status)
         VALUES (:user_id, :email, 'ZiniPay', 'Pending (ZiniPay)', NULL, :amount, 'pending')
         RETURNING id"
    );
    $stmt->execute([
        'user_id' => $walletUserId,
        'email'   => $email,
        'amount'  => $amount
    ]);
    $requestId = (int)$stmt->fetch()['id'];

    // ---- Call ZiniPay Create Invoice ----
    $payload = [
        'cus_email'    => $email,
        'amount'       => $amount,
        'metadata'     => [
            'request_id'     => $requestId,
            'wallet_user_id' => $walletUserId
        ],
        'redirect_url' => $redirectUrlBase . '?request_id=' . $requestId,
        'cancel_url'   => $cancelUrlBase . '?request_id=' . $requestId,
        'webhook_url'  => $webhookUrl
    ];

    $ch = curl_init($zinipayBaseUrl . '/v1/payment/create');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'zini-api-key: ' . $zinipayApiKey
        ]
    ]);
    $zpResponse = curl_exec($ch);
    $zpHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($zpResponse === false) {
        throw new Exception('Could not reach ZiniPay');
    }

    $zpData = json_decode($zpResponse, true);

    if ($zpHttpCode < 200 || $zpHttpCode >= 300 || empty($zpData['status']) || empty($zpData['payment_url'])) {
        // Mark the request as rejected so it doesn't sit as a dead pending row.
        $failStmt = $conn->prepare("UPDATE wallet_deposit_requests SET status = 'rejected', admin_note = 'ZiniPay invoice creation failed' WHERE id = :id");
        $failStmt->execute(['id' => $requestId]);

        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Could not create ZiniPay invoice', 'zinipay_response' => $zpData]);
        exit;
    }

    // ---- Store the invoice_id on the request row ----
    // payment_url looks like https://secure.zinipay.com/payment/INVOICE_ID
    $paymentUrl = $zpData['payment_url'];
    $invoiceId = basename(parse_url($paymentUrl, PHP_URL_PATH));

    $updateStmt = $conn->prepare("UPDATE wallet_deposit_requests SET zinipay_invoice_id = :invoice_id WHERE id = :id");
    $updateStmt->execute([
        'invoice_id' => $invoiceId,
        'id'         => $requestId
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'success'     => true,
    'message'     => 'Invoice created. Redirecting to payment.',
    'request_id'  => $requestId,
    'payment_url' => $paymentUrl
]);

exit;
