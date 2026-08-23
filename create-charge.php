<?php
/**
 * create-charge.php
 *
 * CHTEO Wallet — starts a deposit.
 * Called from the frontend deposit form via POST with:
 *   - user_id        (the wallet_users.id for the logged-in Supabase user)
 *   - full_name       (from Supabase profile)
 *   - email_mobile    (email or phone used for the deposit)
 *   - amount          (deposit amount, e.g. "100")
 *
 * ASSUMPTION: adjust the field names above to match whatever your
 * deposit form on index.html actually POSTs — these are placeholders
 * following the same pattern as get-balance.php / sync-wallet.php.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';            // wallet DB connection ($conn)
require_once __DIR__ . '/piprapay-keys.php'; // PIPRAPAY_API_KEY, PIPRAPAY_BASE_URL

// ---- 1. Read + validate input ----
$user_id       = $_POST['user_id']       ?? '';
$full_name     = $_POST['full_name']     ?? 'Demo';
$email_mobile  = $_POST['email_mobile']  ?? '';
$amount        = $_POST['amount']        ?? '';

if (empty($user_id) || empty($amount) || !is_numeric($amount) || floatval($amount) <= 0) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "user_id and a valid amount are required."]);
    exit;
}

// ---- 2. Build the URLs PipraPay needs ----
// These should point at pages/files that actually exist in your Website- repo.
$webhook_url  = "https://chteo-api.onrender.com/piprapay-webhook.php";
$redirect_url = "https://chtesportsofficial.github.io/Website-/deposit-success.html";
$cancel_url   = "https://chtesportsofficial.github.io/Website-/deposit-cancelled.html";

// ---- 3. Call PipraPay's create-charge endpoint ----
$payload = [
    "full_name"    => $full_name,
    "email_mobile" => $email_mobile,
    "amount"       => (string) $amount,
    "metadata"     => ["wallet_user_id" => (string) $user_id],
    "redirect_url" => $redirect_url,
    "return_type"  => "GET",
    "cancel_url"   => $cancel_url,
    "webhook_url"  => $webhook_url,
    "currency"     => "BDT",
];

$ch = curl_init(rtrim(PIPRAPAY_BASE_URL, '/') . '/create-charge');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: application/json',
    'content-type: application/json',
    'mh-piprapay-api-key: ' . PIPRAPAY_API_KEY,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
    error_log("[create-charge] PipraPay call failed. HTTP $httpCode, curl_error: $curlError, body: $response");
    http_response_code(502);
    echo json_encode(["status" => false, "message" => "Could not start payment, please try again."]);
    exit;
}

$result = json_decode($response, true);

if (!$result) {
    http_response_code(502);
    echo json_encode(["status" => false, "message" => "Unexpected response from payment gateway."]);
    exit;
}

// NOTE: confirm the exact key names once you see a real response —
// common possibilities are pp_id / payment_url, or nested under "data".
$pp_id       = $result['pp_id']        ?? ($result['data']['pp_id'] ?? null);
$payment_url = $result['payment_url']  ?? ($result['data']['payment_url'] ?? ($result['url'] ?? null));

if (!$pp_id || !$payment_url) {
    error_log("[create-charge] Missing pp_id/payment_url in response: " . $response);
    http_response_code(502);
    echo json_encode(["status" => false, "message" => "Payment gateway response was incomplete.", "raw" => $result]);
    exit;
}

// ---- 4. Save a pending transaction row so the webhook can match it later ----
$stmt = $conn->prepare(
    "INSERT INTO wallet_transactions (user_id, pp_id, amount, status, created_at) VALUES (?, ?, ?, 'pending', NOW())"
);
$stmt->bind_param("isd", $user_id, $pp_id, $amount);
$stmt->execute();
$stmt->close();

// ---- 5. Send the payment URL back to the frontend to redirect the user ----
http_response_code(200);
echo json_encode([
    "status"      => true,
    "pp_id"       => $pp_id,
    "payment_url" => $payment_url,
]);
