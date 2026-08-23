<?php
/**
 * piprapay-webhook.php
 *
 * PipraPay webhook receiver — CHTEO Wallet deposit system (Step 6)
 *
 * Flow:
 * 1. PipraPay POSTs a webhook here after a payment attempt.
 * 2. We check the 'mh-piprapay-api-key' header against our stored key.
 * 3. We pull pp_id out of the JSON body.
 * 4. We do NOT trust the webhook body's "status" blindly — we call
 *    PipraPay's own /api/verify-payments endpoint with that pp_id and
 *    only credit the wallet based on THAT authoritative response.
 * 5. We use the wallet_transactions row (matched by pp_id) as an
 *    idempotency guard — if it's already 'completed', we don't credit again.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';            // wallet DB connection ($conn)
require_once __DIR__ . '/piprapay-keys.php'; // defines PIPRAPAY_API_KEY, PIPRAPAY_BASE_URL

// ---- 1. Read raw body + headers ----
$rawData = file_get_contents("php://input");
$data = json_decode($rawData, true);

$headers = getallheaders();

$received_api_key = '';
if (isset($headers['mh-piprapay-api-key'])) {
    $received_api_key = $headers['mh-piprapay-api-key'];
} elseif (isset($headers['Mh-Piprapay-Api-Key'])) {
    $received_api_key = $headers['Mh-Piprapay-Api-Key'];
} elseif (isset($_SERVER['HTTP_MH_PIPRAPAY_API_KEY'])) {
    $received_api_key = $_SERVER['HTTP_MH_PIPRAPAY_API_KEY'];
}

// ---- 2. Auth check ----
if ($received_api_key !== PIPRAPAY_API_KEY) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Unauthorized request."]);
    exit;
}

// ---- 3. Extract pp_id (try a few common shapes, just in case) ----
$pp_id = $data['pp_id'] ?? $data['order_id'] ?? '';

if (empty($pp_id)) {
    // Log raw payload for later debugging, but respond 200 so PipraPay
    // doesn't keep retrying a webhook we genuinely can't use.
    error_log("[piprapay-webhook] Missing pp_id. Raw payload: " . $rawData);
    http_response_code(200);
    echo json_encode(["status" => false, "message" => "pp_id not found, logged for review."]);
    exit;
}

// ---- 4. Ask PipraPay directly: what is the REAL status of this payment? ----
$verifyResult = piprapay_verify_payment($pp_id);

if ($verifyResult === null) {
    // Couldn't reach PipraPay's verify endpoint — don't credit, ask them to retry.
    error_log("[piprapay-webhook] verify-payments call failed for pp_id=$pp_id");
    http_response_code(502);
    echo json_encode(["status" => false, "message" => "Could not verify payment, please retry."]);
    exit;
}

$verifiedStatus = strtolower($verifyResult['status'] ?? '');
$verifiedAmount = $verifyResult['amount'] ?? ($data['amount'] ?? 0);
$verifiedTotal  = $verifyResult['total']  ?? ($data['total']  ?? $verifiedAmount);

$isPaid = in_array($verifiedStatus, ['completed', 'paid', 'success', 'successful']);

// ---- 5. Find the matching transaction row in our own DB ----
$stmt = $conn->prepare("SELECT id, user_id, status FROM wallet_transactions WHERE pp_id = ? LIMIT 1");
$stmt->bind_param("s", $pp_id);
$stmt->execute();
$result = $stmt->get_result();
$txn = $result->fetch_assoc();
$stmt->close();

if (!$txn) {
    error_log("[piprapay-webhook] No wallet_transactions row found for pp_id=$pp_id");
    http_response_code(200);
    echo json_encode(["status" => false, "message" => "No matching transaction on file."]);
    exit;
}

// Idempotency guard: only act if it's still pending
if ($txn['status'] === 'completed') {
    http_response_code(200);
    echo json_encode(["status" => true, "message" => "Already processed."]);
    exit;
}

if (!$isPaid) {
    // Payment failed/pending per PipraPay's own verification — mark as such, no credit.
    $newStatus = $verifiedStatus ?: 'failed';
    $stmt = $conn->prepare("UPDATE wallet_transactions SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $txn['id']);
    $stmt->execute();
    $stmt->close();

    http_response_code(200);
    echo json_encode(["status" => true, "message" => "Transaction status updated to $newStatus."]);
    exit;
}

// ---- 6. Payment verified as completed — credit balance atomically ----
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE wallet_users SET balance = balance + ? WHERE id = ?");
    $stmt->bind_param("di", $verifiedTotal, $txn['user_id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE wallet_transactions SET status = 'completed', amount = ? WHERE id = ?");
    $stmt->bind_param("di", $verifiedTotal, $txn['id']);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    http_response_code(200);
    echo json_encode(["status" => true, "message" => "Wallet credited successfully."]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("[piprapay-webhook] Credit failed for pp_id=$pp_id: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Internal error while crediting wallet."]);
}
exit;


/**
 * Calls PipraPay's verify-payments endpoint and returns the decoded
 * response array, or null on failure.
 */
function piprapay_verify_payment($pp_id)
{
    // PIPRAPAY_BASE_URL already ends in /api for this self-hosted instance
    // (e.g. https://chteo-wallet-piprapay-1.onrender.com/api), so we just
    // append the endpoint name — no extra /api segment.
    $url = rtrim(PIPRAPAY_BASE_URL, '/') . '/verify-payments';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['pp_id' => $pp_id]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'content-type: application/json',
        'mh-piprapay-api-key: ' . PIPRAPAY_API_KEY,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("[piprapay-webhook] verify-payments HTTP $httpCode, curl_error: $curlError, body: $response");
        return null;
    }

    $decoded = json_decode($response, true);
    return $decoded ?: null;
}
