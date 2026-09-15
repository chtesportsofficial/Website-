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

// Same Telegram group/config as submit-withdraw-request.php, so guest
// prize withdraws show up in the same "CHTEO Withdraw Alerts" group.
$telegramBotToken = getenv('TELEGRAM_BOT_TOKEN') ?: '8946675932:AAHxGR-v1JoGVDmpKJYnpqriKpF7swjSKkE';
$telegramChatId   = getenv('TELEGRAM_WITHDRAW_CHAT_ID') ?: '-5433914490';

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$withdrawals = isset($data['withdrawals']) && is_array($data['withdrawals']) ? $data['withdrawals'] : [];

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}
if (!count($withdrawals)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No withdrawals provided']);
    exit;
}

/* Verify requester. */
$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$authResponse = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$requester = json_decode($authResponse, true);
if ($authResponse === false || $authCode !== 200 || empty($requester['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired session']);
    exit;
}
$requesterId = $requester['id'];

/* Check admin/owner — this is an admin-only action, unlike the normal
   user-facing submit-withdraw-request.php. */
$ch = curl_init(
    rtrim($supabaseUrl, '/') .
    '/rest/v1/profiles?id=eq.' . urlencode($requesterId) .
    '&select=is_admin,is_owner'
);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$profileResponse = curl_exec($ch);
$profileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$rows = json_decode($profileResponse, true);
$profile = (is_array($rows) && count($rows) > 0) ? $rows[0] : null;
$isAdmin = $profile && (!empty($profile['is_admin']) || !empty($profile['is_owner']));

if ($profileResponse === false || $profileCode < 200 || $profileCode >= 300 || !$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

/* For each guest winner: no wallet exists to deduct/credit, so this files
   a withdraw request directly (skipping the "deduct from balance" step
   that submit-withdraw-request.php does for real users). The admin pays
   it out manually via admin-withdraw.html like any other withdraw request.

   wallet_prize_credits (reference = prize_<lobby_id>_<team_id>, UNIQUE,
   sentinel user_id = 0 for guests) now tracks the RUNNING TOTAL prize
   claimed for this exact team/lobby — same role it plays in
   credit-prize.php for signed-in winners — instead of just being a
   one-shot duplicate guard. wallet_withdraw_requests.reference (same
   value, NOT unique on that table) links back to it so a resubmit can
   find the request it should update.

   - First time this reference is seen: insert the wallet_prize_credits
     row, insert a new PENDING wallet_withdraw_requests row for the full
     amount.
   - Reference already exists (host edited the prize and saved again) —
     look up the most recent wallet_withdraw_requests row for it:
       - status = 'pending' (admin hasn't paid yet): that SAME row is
         updated in place to the new full amount/method/number. Only one
         pending entry ever exists per team/lobby, so the admin never
         sees confusing duplicate rows for one payout.
       - any other status (approved/rejected/etc — money already moved,
         or the request is otherwise closed): that row can't be edited
         retroactively. Only the DIFFERENCE between the new and old total
         is filed as a brand-new, separate "adjustment" request. A
         downward change here is skipped (not silently dropped) since a
         request that's already been paid can't be safely reduced/undone
         automatically — the admin has to handle that one manually.
   - If the amount is unchanged, nothing happens (no-op skip).

   IMPORTANT Postgres difference from MySQL: once any statement inside a
   transaction errors, Postgres aborts the WHOLE transaction — every
   further statement fails until a ROLLBACK, even unrelated ones. MySQL
   just fails that one statement. So each attempt here runs inside its
   own SAVEPOINT: on a duplicate-key race (SQLSTATE 23505, two requests
   inserting the same brand-new reference at once) we roll back only to
   that savepoint, keeping the outer transaction alive for the rest of
   the loop. */
$conn->beginTransaction();

try {
    $refInsertStmt = $conn->prepare(
        "INSERT INTO wallet_prize_credits (reference, user_id, amount) VALUES (:reference, 0, :amount)"
    );
    $existingCreditStmt = $conn->prepare(
        "SELECT amount FROM wallet_prize_credits WHERE reference = :reference FOR UPDATE"
    );
    $updateCreditStmt = $conn->prepare(
        "UPDATE wallet_prize_credits SET amount = :new_amount WHERE reference = :reference"
    );
    $latestRequestStmt = $conn->prepare(
        "SELECT id, status FROM wallet_withdraw_requests
         WHERE reference = :reference
         ORDER BY created_at DESC LIMIT 1"
    );
    $updatePendingStmt = $conn->prepare(
        "UPDATE wallet_withdraw_requests
         SET amount = :amount, method = :method, account_number = :account_number, guest_note = :guest_note
         WHERE id = :id"
    );
    $insertRequestStmt = $conn->prepare(
        "INSERT INTO wallet_withdraw_requests
            (user_id, email, amount, method, account_number, status, is_guest, guest_note, reference, created_at)
         VALUES ('', 'Guest', :amount, :method, :account_number, 'pending', true, :guest_note, :reference, NOW())
         RETURNING id"
    );

    $submitted = []; // rows that resulted in a NEW request (for Telegram notify)
    $updated = [];   // rows that updated an existing pending request
    $skipped = [];

    foreach ($withdrawals as $w) {
        $lobbyId = isset($w['lobby_id']) ? trim((string)$w['lobby_id']) : '';
        $teamId = isset($w['team_id']) ? trim((string)$w['team_id']) : '';
        $amount = isset($w['amount']) ? (float)$w['amount'] : 0;
        $method = isset($w['method']) ? trim($w['method']) : '';
        $accountNumber = isset($w['account_number']) ? trim($w['account_number']) : '';
        $guestNote = isset($w['guest_note']) ? trim($w['guest_note']) : 'Tournament prize (Guest)';

        if ($lobbyId === '' || $teamId === '' || $amount <= 0) {
            $skipped[] = $w + ['reason' => 'invalid lobby/team/amount'];
            continue;
        }
        if (!in_array($method, ['Bkash', 'Nagad'], true)) {
            $skipped[] = $w + ['reason' => 'invalid method'];
            continue;
        }
        if (!preg_match('/^01[0-9]{9}$/', $accountNumber)) {
            $skipped[] = $w + ['reason' => 'invalid account number'];
            continue;
        }

        $reference = 'prize_' . $lobbyId . '_' . $teamId;

        $conn->exec('SAVEPOINT prize_claim');

        try {
            $existingCreditStmt->execute(['reference' => $reference]);
            $existingCreditRow = $existingCreditStmt->fetch();

            if ($existingCreditRow === false) {
                // First time this exact team/lobby prize is being requested.
                $refInsertStmt->execute(['reference' => $reference, 'amount' => $amount]);

                $insertRequestStmt->execute([
                    'amount' => $amount,
                    'method' => $method,
                    'account_number' => $accountNumber,
                    'guest_note' => $guestNote,
                    'reference' => $reference
                ]);
                $insertedRow = $insertRequestStmt->fetch();

                $submitted[] = [
                    'lobby_id' => $lobbyId, 'team_id' => $teamId,
                    'amount' => $amount, 'request_id' => (int)$insertedRow['id']
                ];
                $conn->exec('RELEASE SAVEPOINT prize_claim');
                continue;
            }

            // Reference already claimed before — figure out what to do
            // with the difference based on the latest request's status.
            $oldAmount = (float)$existingCreditRow['amount'];
            $diff = round($amount - $oldAmount, 2);

            if (abs($diff) < 0.005) {
                $conn->exec('RELEASE SAVEPOINT prize_claim');
                $skipped[] = $w + ['reason' => 'already submitted (amount unchanged)'];
                continue;
            }

            $latestRequestStmt->execute(['reference' => $reference]);
            $latestRequest = $latestRequestStmt->fetch();

            if ($latestRequest && $latestRequest['status'] === 'pending') {
                // Not paid yet — safe to edit that same request in place,
                // to the new FULL amount (not the diff).
                $updateCreditStmt->execute(['new_amount' => $amount, 'reference' => $reference]);
                $updatePendingStmt->execute([
                    'amount' => $amount,
                    'method' => $method,
                    'account_number' => $accountNumber,
                    'guest_note' => $guestNote,
                    'id' => $latestRequest['id']
                ]);
                $updated[] = [
                    'lobby_id' => $lobbyId, 'team_id' => $teamId,
                    'amount' => $amount, 'request_id' => (int)$latestRequest['id']
                ];
                $conn->exec('RELEASE SAVEPOINT prize_claim');
                continue;
            }

            // Latest request is already approved/rejected/closed (or
            // missing entirely) — it can't be edited retroactively.
            if ($diff < 0) {
                $conn->exec('ROLLBACK TO SAVEPOINT prize_claim');
                $skipped[] = $w + ['reason' => 'prize lowered after the earlier request was already processed — adjust it manually'];
                continue;
            }

            // Diff is positive: file a brand-new adjustment request for
            // just the extra amount, sharing the same reference for
            // traceability (this table's reference is NOT unique).
            $updateCreditStmt->execute(['new_amount' => $amount, 'reference' => $reference]);
            $adjNote = 'Adjustment (+৳' . $diff . ') — ' . $guestNote;
            $insertRequestStmt->execute([
                'amount' => $diff,
                'method' => $method,
                'account_number' => $accountNumber,
                'guest_note' => $adjNote,
                'reference' => $reference
            ]);
            $insertedRow = $insertRequestStmt->fetch();

            $submitted[] = [
                'lobby_id' => $lobbyId, 'team_id' => $teamId,
                'amount' => $diff, 'request_id' => (int)$insertedRow['id'], 'adjustment' => true
            ];
            $conn->exec('RELEASE SAVEPOINT prize_claim');
        } catch (PDOException $ex) {
            $conn->exec('ROLLBACK TO SAVEPOINT prize_claim');
            $sqlState = $ex->errorInfo[0] ?? null;
            if ($sqlState === '23505') {
                $skipped[] = $w + ['reason' => 'already submitted (concurrent request)'];
                continue;
            }
            throw new Exception('Could not reserve/update prize reference: ' . $ex->getMessage());
        }
    }

    $conn->commit();

    // Best-effort Telegram notify (mirrors submit-withdraw-request.php) —
    // only for genuinely NEW requests, never for in-place pending updates,
    // and never allowed to fail the response.
    foreach ($submitted as $s) {
        $telegramText =
            (!empty($s['adjustment']) ? "🟣 GUEST Prize Adjustment Request\n" : "🟣 New GUEST Prize Withdraw Request\n") .
            "Amount: ৳" . number_format($s['amount'], 2) . "\n" .
            "Lobby: " . $s['lobby_id'] . " / Team: " . $s['team_id'] . "\n" .
            "Request ID: " . $s['request_id'] . "\n" .
            "Time: " . (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('d M Y, h:i A');
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
        curl_exec($tgCh);
        curl_close($tgCh);
    }

    echo json_encode([
        'success' => true,
        'message' => count($submitted) . ' new request(s), ' . count($updated) . ' pending request(s) updated.',
        'submitted' => $submitted,
        'updated' => $updated,
        'skipped' => $skipped
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
