<?php
// zinipay-tournament-webhook.php
// Called by ZiniPay after a tournament-entry payment update. Verifies with
// ZiniPay directly (never trusts the webhook body alone), then — since there
// is no logged-in user session at this point — inserts the lobby_teams
// row(s) and decrements booked_slots itself via the Supabase REST API using
// the service role key (same pattern as admin-auth.php's verify_admin_token,
// which also needs to bypass RLS).

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';        // exposes $conn (PDO, Postgres)
require_once __DIR__ . '/../admin-auth.php'; // exposes supabase_curl(), SUPABASE_URL, SUPABASE_SERVICE_KEY

$zinipayApiKey  = getenv('ZINIPAY_API_KEY') ?: '';
$zinipayBaseUrl = 'https://api.zinipay.com';

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

$invoiceId = '';
if (is_array($body) && !empty($body['invoice_id'])) {
    $invoiceId = trim($body['invoice_id']);
} elseif (!empty($_GET['invoice_id'])) {
    $invoiceId = trim($_GET['invoice_id']);
}

if ($invoiceId === '' || $zinipayApiKey === '') {
    error_log('[zinipay-tournament-webhook] Missing invoice_id or API key. Payload: ' . $rawInput);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Missing invoice_id or server not configured']);
    exit;
}

// ---- Verify with ZiniPay directly ----
$ch = curl_init($zinipayBaseUrl . '/v1/payment/verify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoiceId]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'zini-api-key: ' . $zinipayApiKey
    ]
]);
$verifyResponse = curl_exec($ch);
$verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($verifyResponse === false) {
    error_log('[zinipay-tournament-webhook] Could not reach ZiniPay verify for ' . $invoiceId);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Could not verify with ZiniPay']);
    exit;
}

$verifyData = json_decode($verifyResponse, true);
if ($verifyHttpCode < 200 || $verifyHttpCode >= 300 || !is_array($verifyData)) {
    error_log('[zinipay-tournament-webhook] Bad verify response for ' . $invoiceId . ': ' . $verifyResponse);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Verify failed']);
    exit;
}

if (($verifyData['status'] ?? '') !== 'COMPLETED') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Noted, not completed yet', 'status' => $verifyData['status'] ?? null]);
    exit;
}

$verifiedAmount = isset($verifyData['amount']) ? (float)$verifyData['amount'] : 0;
$verifiedMethod = $verifyData['payment_method'] ?? 'zinipay';
$verifiedTrxId  = $verifyData['transaction_id'] ?? null;

$conn->beginTransaction();

try {
    $stmt = $conn->prepare(
        "SELECT id, supabase_uid, uid_number, amount, whatsapp, lobby_ids, entries, status
         FROM zinipay_tournament_invoices WHERE zinipay_invoice_id = ? FOR UPDATE"
    );
    $stmt->execute([$invoiceId]);
    $record = $stmt->fetch();

    if (!$record) {
        throw new Exception('No matching tournament invoice for ' . $invoiceId);
    }

    // Idempotency — webhook can fire more than once.
    if ($record['status'] !== 'pending') {
        $conn->commit();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }

    if (abs($verifiedAmount - (float)$record['amount']) > 0.01) {
        throw new Exception('Amount mismatch: expected ' . $record['amount'] . ' but ZiniPay verified ' . $verifiedAmount);
    }

    $entries  = json_decode($record['entries'], true) ?: [];
    $lobbyIds = json_decode($record['lobby_ids'], true) ?: [];

    if (empty($entries)) {
        throw new Exception('No entries stored for this invoice');
    }

    // ---- Insert lobby_teams rows via Supabase REST (service key bypasses RLS,
    //      since there is no user session at webhook time) ----
    $rows = array_map(function ($e) use ($record, $verifiedMethod) {
        return [
            'lobby_id'            => $e['lobbyId'] ?? null,
            'team_name'           => $e['teamName'] ?? null,
            'owner_team_name'     => $e['ownerTeamName'] ?? null,
            'players'             => [['name' => $e['playerName'] ?? null]],
            'contact_number'      => $record['whatsapp'],
            'payment_reference'   => 'ZiniPay (' . $verifiedMethod . ') ✓',
            'payment_method'      => 'manual',
            'submitted_by'        => $record['supabase_uid'],
            'confirmation_status' => 'confirmed'
        ];
    }, $entries);

    // supabase_curl() (from admin-auth.php) only takes (url, headers) for
    // GET-style calls — POST needs its own cURL here since it doesn't
    // accept a body/method param.
    $ch = curl_init(SUPABASE_URL . '/rest/v1/lobby_teams');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($rows),
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
            'Prefer: return=minimal'
        ]
    ]);
    curl_exec($ch);
    $insertHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($insertHttpCode < 200 || $insertHttpCode >= 300) {
        throw new Exception('Failed to insert lobby_teams rows (HTTP ' . $insertHttpCode . ')');
    }

    // ---- Decrement booked_slots for each lobby (mirrors decrementBookedSlotsAfterJoin) ----
    foreach ($lobbyIds as $lobbyId) {
        $ch = curl_init(SUPABASE_URL . '/rest/v1/tournament_lobbies?id=eq.' . urlencode($lobbyId) . '&select=booked_slots');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . SUPABASE_SERVICE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
            ]
        ]);
        $lobbyRes = curl_exec($ch);
        curl_close($ch);
        $lobbyRows = json_decode($lobbyRes, true);
        $currentBooked = is_array($lobbyRows) && !empty($lobbyRows[0]['booked_slots']) ? (int)$lobbyRows[0]['booked_slots'] : 0;

        if ($currentBooked > 0) {
            $ch = curl_init(SUPABASE_URL . '/rest/v1/tournament_lobbies?id=eq.' . urlencode($lobbyId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode(['booked_slots' => $currentBooked - 1]),
                CURLOPT_HTTPHEADER => [
                    'apikey: ' . SUPABASE_SERVICE_KEY,
                    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
                    'Content-Type: application/json',
                    'Prefer: return=minimal'
                ]
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    $stmt = $conn->prepare(
        "UPDATE zinipay_tournament_invoices
         SET status = 'completed', trx_id = ?, completed_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$verifiedTrxId, $record['id']]);

    $conn->commit();
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Tournament entry confirmed']);

} catch (Exception $e) {
    $conn->rollBack();
    error_log('[zinipay-tournament-webhook] Failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
