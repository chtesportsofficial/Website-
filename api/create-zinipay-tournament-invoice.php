<?php
// create-zinipay-tournament-invoice.php
// "Pay via bKash/Nagad" path for tournament slot booking — separate from the
// wallet deposit flow (create-zinipay-invoice.php). No wallet balance is
// touched here; the entry fee goes straight through ZiniPay, and the
// lobby_teams row only gets created once payment is confirmed (see
// zinipay-tournament-webhook.php), because the user leaves this page to pay.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';        // exposes $conn (PDO, Postgres)
require_once __DIR__ . '/../admin-auth.php'; // exposes verify_user_token(), SUPABASE_* constants

$zinipayApiKey  = getenv('ZINIPAY_API_KEY') ?: '';
$zinipayBaseUrl = 'https://api.zinipay.com';
$webhookUrl     = getenv('ZINIPAY_TOURNAMENT_WEBHOOK_URL') ?: '';

// TODO: same as create-zinipay-invoice.php — set real pages, domain must
// match the website URL registered on the ZiniPay brand.
$redirectUrlBase = 'https://chtesportsofficial.github.io/Website-/tournament-details.html';
$cancelUrlBase    = 'https://chtesportsofficial.github.io/Website-/tournament-details.html';

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($input['access_token']) ? trim($input['access_token']) : '';
$amount      = isset($input['amount']) ? (float)$input['amount'] : 0;
$entries     = isset($input['entries']) && is_array($input['entries']) ? $input['entries'] : [];
$lobbyIds    = isset($input['lobby_ids']) && is_array($input['lobby_ids']) ? $input['lobby_ids'] : [];
$whatsapp    = isset($input['whatsapp']) ? trim((string)$input['whatsapp']) : '';
$uidNumber   = isset($input['uid']) ? trim((string)$input['uid']) : '';
$title       = isset($input['tournament_title']) ? trim((string)$input['tournament_title']) : '';
$tournamentId= isset($input['tournament_id']) ? trim((string)$input['tournament_id']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing auth token']);
    exit;
}

$verifiedUid = verify_user_token($accessToken);
if (!$verifiedUid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid amount is required']);
    exit;
}
if (empty($entries) || empty($lobbyIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing entry/lobby details']);
    exit;
}
if ($whatsapp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'WhatsApp number is required']);
    exit;
}
if ($tournamentId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing tournament_id']);
    exit;
}

try {
    // ---- Insert the pending invoice record first (self-contained table —
    //      not wallet_deposit_requests, this never touches wallet balance) ----
    $stmt = $conn->prepare(
        "INSERT INTO zinipay_tournament_invoices
            (supabase_uid, uid_number, tournament_id, amount, whatsapp, tournament_title, lobby_ids, entries, status)
         VALUES (:supabase_uid, :uid_number, :tournament_id, :amount, :whatsapp, :tournament_title, :lobby_ids, :entries, 'pending')
         RETURNING id"
    );
    $stmt->execute([
        'supabase_uid'     => $verifiedUid,
        'uid_number'       => $uidNumber,
        'tournament_id'    => $tournamentId,
        'amount'           => $amount,
        'whatsapp'         => $whatsapp,
        'tournament_title' => $title,
        'lobby_ids'        => json_encode($lobbyIds),
        'entries'          => json_encode($entries)
    ]);
    $recordId = (int)$stmt->fetch()['id'];

    // ---- Call ZiniPay Create Invoice ----
    $payload = [
        'amount'       => $amount,
        'metadata'     => ['record_id' => $recordId, 'kind' => 'tournament_entry'],
        // Must keep ?id=<tournament_id> too — tournament-details.html can't
        // render at all without it, so dropping it here would break the page
        // the user lands back on after paying.
        'redirect_url' => $redirectUrlBase . '?id=' . urlencode($tournamentId) . '&entry_paid=' . $recordId,
        'cancel_url'   => $cancelUrlBase . '?id=' . urlencode($tournamentId) . '&entry_cancelled=' . $recordId,
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
        $failStmt = $conn->prepare("UPDATE zinipay_tournament_invoices SET status = 'failed' WHERE id = :id");
        $failStmt->execute(['id' => $recordId]);
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Could not create ZiniPay invoice', 'zinipay_response' => $zpData]);
        exit;
    }

    $paymentUrl = $zpData['payment_url'];
    $invoiceId = basename(parse_url($paymentUrl, PHP_URL_PATH));

    $updateStmt = $conn->prepare("UPDATE zinipay_tournament_invoices SET zinipay_invoice_id = :invoice_id WHERE id = :id");
    $updateStmt->execute(['invoice_id' => $invoiceId, 'id' => $recordId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'success'     => true,
    'record_id'   => $recordId,
    'payment_url' => $paymentUrl
]);
exit;
