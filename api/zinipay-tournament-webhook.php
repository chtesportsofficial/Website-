<?php
// zinipay-tournament-webhook.php
// Called by ZiniPay after a tournament-entry payment update: {invoice_id, status}
// as JSON body OR as query params (?invoice_id=...&status=...).
// Mirrors zinipay-webhook.php's "never trust the webhook body, always call
// /v1/payment/verify ourselves" pattern — but instead of crediting a wallet,
// it creates the lobby_teams row(s) and decrements booked_slots for the
// pending zinipay_tournament_invoices record created by
// create-zinipay-tournament-invoice.php, because the user left this page to
// pay, so no lobby_teams row exists yet.
//
// Always responds 200 to ZiniPay (even on internal errors) so it doesn't
// keep retrying forever; real problems are written to error_log for us to check.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php'; // exposes $conn (PDO, Postgres)

$zinipayApiKey  = getenv('ZINIPAY_API_KEY') ?: '';
$zinipayBaseUrl = 'https://api.zinipay.com';

// ---- Read invoice_id from JSON body first, then fall back to query params ----
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

$invoiceId = '';
if (is_array($body) && !empty($body['invoice_id'])) {
    $invoiceId = trim($body['invoice_id']);
} elseif (!empty($_GET['invoice_id'])) {
    $invoiceId = trim($_GET['invoice_id']);
}

if ($invoiceId === '') {
    error_log('[zinipay-tournament-webhook] Missing invoice_id in payload: ' . $rawInput);
    http_response_code(200); // acknowledge anyway, nothing to retry
    echo json_encode(['success' => false, 'message' => 'Missing invoice_id']);
    exit;
}

if ($zinipayApiKey === '') {
    error_log('[zinipay-tournament-webhook] ZINIPAY_API_KEY not configured');
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Server not configured']);
    exit;
}

// ---- Verify the invoice directly with ZiniPay before trusting anything ----
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
    error_log('[zinipay-tournament-webhook] Could not reach ZiniPay verify endpoint for invoice ' . $invoiceId);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Could not verify with ZiniPay']);
    exit;
}

$verifyData = json_decode($verifyResponse, true);

if ($verifyHttpCode < 200 || $verifyHttpCode >= 300 || !is_array($verifyData)) {
    error_log('[zinipay-tournament-webhook] Bad verify response for invoice ' . $invoiceId . ': ' . $verifyResponse);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Verify failed']);
    exit;
}

$paymentStatus = $verifyData['status'] ?? '';

if ($paymentStatus !== 'COMPLETED') {
    // PENDING or FAILED — nothing to book yet. Just acknowledge.
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Noted, not completed yet', 'status' => $paymentStatus]);
    exit;
}

$verifiedAmount = isset($verifyData['amount']) ? (float)$verifyData['amount'] : 0;
$verifiedMethod = $verifyData['payment_method'] ?? 'ZiniPay';
$verifiedTrxId  = $verifyData['transaction_id'] ?? null;

$conn->beginTransaction();

try {
    // Lock the matching invoice record.
    $stmt = $conn->prepare(
        "SELECT id, supabase_uid, uid_number, amount, whatsapp, lobby_ids, entries, status
         FROM zinipay_tournament_invoices
         WHERE zinipay_invoice_id = ? FOR UPDATE"
    );
    $stmt->execute([$invoiceId]);
    $req = $stmt->fetch();

    if (!$req) {
        throw new Exception('No matching tournament invoice for ' . $invoiceId);
    }

    // Idempotency: if this was already processed (webhook fired twice), don't
    // create duplicate lobby_teams rows or decrement slots twice.
    if ($req['status'] !== 'pending') {
        $conn->commit();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }

    // Sanity check: the verified amount should match what we asked for.
    if (abs($verifiedAmount - (float)$req['amount']) > 0.01) {
        throw new Exception('Amount mismatch: requested ' . $req['amount'] . ' but ZiniPay verified ' . $verifiedAmount);
    }

    $entries  = json_decode($req['entries'], true) ?: [];
    $lobbyIds = json_decode($req['lobby_ids'], true) ?: [];
    if (empty($entries) || empty($lobbyIds)) {
        throw new Exception('Invoice record ' . $req['id'] . ' has no entries/lobby_ids');
    }

    // ---- Look up each lobby's time_label for Telegram routing ----
    // Unlike the balance-payment path (deduct-balance.php), the entries saved
    // on this invoice don't carry time_label (create-zinipay-tournament-invoice.php
    // never receives it from the frontend for this payment method), so we pull
    // it here from tournament_lobbies keyed by lobby_id instead.
    $timeLabelByLobbyId = [];
    $uniqueLobbyIdsForLabels = array_unique($lobbyIds);
    if (!empty($uniqueLobbyIdsForLabels)) {
        $placeholders = implode(',', array_fill(0, count($uniqueLobbyIdsForLabels), '?'));
        $labelStmt = $conn->prepare("SELECT id, time_label FROM tournament_lobbies WHERE id IN ($placeholders)");
        $labelStmt->execute(array_values($uniqueLobbyIdsForLabels));
        foreach ($labelStmt->fetchAll() as $lobbyRow2) {
            $timeLabelByLobbyId[$lobbyRow2['id']] = $lobbyRow2['time_label'];
        }
    }

    // lobby_teams.payment_method has a CHECK constraint allowing only
    // 'balance' or 'manual' — unlike wallet_deposit_requests.method, it does
    // NOT accept a free-form label. Use 'manual' and put the ZiniPay-specific
    // detail (which method, transaction id) into payment_reference instead,
    // so admins can still see it was auto-verified via ZiniPay.
    $methodLower = strtolower((string)$verifiedMethod);
    $methodLabel = (strpos($methodLower, 'nagad') !== false) ? 'Nagad' : 'Bkash';
    $paymentMethodValue    = 'manual';
    $paymentReferenceValue = 'ZiniPay auto-verified (' . $methodLabel . ') — trx: ' . $verifiedTrxId;

    // ---- Same shape as the balance-payment insert in submitJoin(), just
    //      built server-side now that payment is confirmed ----
    $insertStmt = $conn->prepare(
        "INSERT INTO lobby_teams
            (lobby_id, team_name, owner_team_name, players, contact_number,
             payment_reference, payment_method, submitted_by, confirmation_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')"
    );

    $firstOwnerTeamName = null;
    $insertedCount = 0;
    $entriesForNotify = [];
    foreach ($entries as $e) {
        $lobbyId       = $e['lobby_id'] ?? null;
        $teamName      = trim((string)($e['team_name'] ?? ''));
        $playerName    = trim((string)($e['player_name'] ?? ''));
        $ownerTeamName = trim((string)($e['owner_team_name'] ?? $teamName));
        if ($firstOwnerTeamName === null && $ownerTeamName !== '') {
            $firstOwnerTeamName = $ownerTeamName;
        }
        if ($lobbyId === null || $teamName === '' || $playerName === '') {
            error_log('[zinipay-tournament-webhook] Skipping malformed entry on invoice ' . $req['id'] . ': ' . json_encode($e));
            continue;
        }

        $insertStmt->execute([
            $lobbyId,
            $teamName,
            $ownerTeamName,
            json_encode([['name' => $playerName]]),
            $req['whatsapp'],
            $paymentReferenceValue,
            $paymentMethodValue,
            $req['supabase_uid']
        ]);
        $insertedCount++;

        $entriesForNotify[] = [
            'team_name'   => $teamName,
            'player_name' => $playerName,
            'time_label'  => $timeLabelByLobbyId[$lobbyId] ?? ''
        ];
    }

    if ($insertedCount === 0) {
        throw new Exception('No valid entries could be inserted for invoice ' . $req['id']);
    }

    // Decrement booked_slots once per unique selected lobby — 1 slot per
    // lobby regardless of how many teammate sub-entries it has, exactly like
    // decrementBookedSlotsAfterJoin() does on the balance-payment path.
    foreach (array_unique($lobbyIds) as $lobbyId) {
        $slotStmt = $conn->prepare("SELECT booked_slots FROM tournament_lobbies WHERE id = ? FOR UPDATE");
        $slotStmt->execute([$lobbyId]);
        $lobbyRow = $slotStmt->fetch();
        if (!$lobbyRow) continue;
        $current = max(0, (int)$lobbyRow['booked_slots']);
        if ($current <= 0) continue;
        $upd = $conn->prepare("UPDATE tournament_lobbies SET booked_slots = ? WHERE id = ?");
        $upd->execute([$current - 1, $lobbyId]);
    }

    // First-time team name: mirrors submitJoin()'s client-side profile sync —
    // only fill it in if the profile doesn't have one yet.
    if ($firstOwnerTeamName) {
        $profStmt = $conn->prepare("SELECT full_name FROM profiles WHERE id = ?");
        $profStmt->execute([$req['supabase_uid']]);
        $prof = $profStmt->fetch();
        if ($prof && trim((string)($prof['full_name'] ?? '')) === '') {
            $updProf = $conn->prepare("UPDATE profiles SET full_name = ? WHERE id = ?");
            $updProf->execute([$firstOwnerTeamName, $req['supabase_uid']]);
        }
    }

    $doneStmt = $conn->prepare(
        "UPDATE zinipay_tournament_invoices SET status = 'completed', zinipay_transaction_id = ? WHERE id = ?"
    );
    $doneStmt->execute([$verifiedTrxId, $req['id']]);

    $conn->commit();

    // ---- Notify admin on Telegram, routed to the group for THIS purchase's
    //      slot time(s) — same as the balance-payment path in deduct-balance.php,
    //      just ported here since ZiniPay-confirmed joins never had this call
    //      (best-effort — never breaks the response if Telegram is down/misconfigured).
    $slotTimesForNotify = array_values(array_unique(array_filter(
        array_map(function($lid) use ($timeLabelByLobbyId) { return $timeLabelByLobbyId[$lid] ?? ''; }, array_unique($lobbyIds))
    )));
    notifyTelegramSlotPurchase($req['supabase_uid'], $verifiedAmount, $paymentReferenceValue, $slotTimesForNotify, $entriesForNotify, $req['whatsapp'], $req['uid_number']);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Tournament entry confirmed', 'entries_created' => $insertedCount]);

} catch (Exception $e) {
    $conn->rollBack();
    error_log('[zinipay-tournament-webhook] Failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
    http_response_code(200); // still 200 so ZiniPay doesn't hammer retries; we log for ourselves
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ---- Telegram notification (routed by slot time) ----
// Copied from deduct-balance.php so both payment paths (wallet balance and
// ZiniPay bKash/Nagad auto-verify) notify the same "SLOT UPDATES" groups.
// If the bot token or any chat id ever changes, update it in BOTH files.
function notifyTelegramSlotPurchase($who, $amount, $reference, $slotTimes, $entries, $whatsapp, $uid) {
    $BOT_TOKEN = '8946675932:AAHxGR-v1JoGVDmpKJYnpqriKpF7swjSKkE'; // <-- same bot token as deduct-balance.php
    $CHAT_ID_BY_HOUR = [
        9  => '-5357739634',  // 9 PM SLOT UPDATES
        10 => '-5143302387',  // 10 PM SLOT UPDATES
        11 => '-5378353079',  // 11 PM SLOT UPDATES
        12 => '-5374244237',  // 12 AM SLOT UPDATES
        3  => '-5147853479',  // 3 PM SLOT UPDATES
        4  => '-5587751748',  // 4 PM SLOT UPDATES
        5  => '-5524224556',  // 5 PM SLOT UPDATES
        7  => '-5502010780',  // 7 PM SLOT UPDATES
        8  => '-5483654693',  // 8 PM SLOT UPDATES
    ];
    if ($BOT_TOKEN === '' || empty($slotTimes) || !is_array($slotTimes)) return;

    function extractHourZiniTournament($t) {
        return preg_match('/^(\d{1,2}):/', trim((string)$t), $m) ? (int)$m[1] : null;
    }

    $entriesByHour = [];
    foreach ($entries as $e) {
        $hour = extractHourZiniTournament($e['time_label'] ?? '');
        if ($hour === null) continue;
        $entriesByHour[$hour][] = $e;
    }

    $hours = [];
    foreach ($slotTimes as $t) {
        $hour = extractHourZiniTournament($t);
        if ($hour !== null) $hours[$hour] = true;
    }

    foreach (array_keys($hours) as $hour) {
        if (empty($CHAT_ID_BY_HOUR[$hour])) continue;
        $chatId = $CHAT_ID_BY_HOUR[$hour];

        $lines = [];
        $theseEntries = $entriesByHour[$hour] ?? [];
        foreach ($theseEntries as $e) {
            $lines[] = "TEAM NAME: " . ($e['team_name'] ?? '-') . "\n"
                     . "PLAYER NAME: " . ($e['player_name'] ?? '-');
        }

        $text = "UID: " . ($uid !== '' ? '#'.$uid : '-') . "\n"
              . "WHATSAPP NUMBER: " . ($whatsapp ?: '-');
        if ($lines) {
            $text .= "\n\n" . implode("\n\n", $lines);
        }

        $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text]),
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
