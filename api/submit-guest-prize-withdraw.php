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

/* For each guest winner: no wallet exists to deduct/credit, so this just
   files a PENDING withdraw request directly (skipping the "deduct from
   balance" step that submit-withdraw-request.php does for real users).
   The admin pays it out manually via admin-withdraw.html like any other
   withdraw request.

   Same duplicate-guard as credit-prize.php: a row is inserted into
   wallet_prize_credits FIRST, keyed on reference = prize_<lobby_id>_<team_id>
   with a sentinel user_id of 0 (guests have no wallet_users.id). Because
   that reference is UNIQUE, Postgres itself blocks a double-click / retry
   from ever filing the same guest prize twice — and it also blocks a mixup
   with credit-prize.php ever double-paying the same team through both
   paths, since they share the exact same reference format. */
$conn->beginTransaction();

try {
    $refStmt = $conn->prepare(
        "INSERT INTO wallet_prize_credits (reference, user_id, amount) VALUES (:reference, 0, :amount)"
    );
    $insertStmt = $conn->prepare(
        "INSERT INTO wallet_withdraw_requests
            (user_id, email, amount, method, account_number, status, is_guest, guest_note, created_at)
         VALUES ('', 'Guest', :amount, :method, :account_number, 'pending', true, :guest_note, NOW())
         RETURNING id"
    );

    $submitted = [];
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

        // Atomically claim this exact prize. Duplicate => already processed.
        // Postgres reports a unique-violation as SQLSTATE 23505 (MySQL used
        // errno 1062 for the same thing).
        //
        // IMPORTANT Postgres difference from MySQL: once any statement
        // inside a transaction errors, Postgres marks the WHOLE transaction
        // "aborted" and refuses every further statement until a ROLLBACK —
        // unlike MySQL, which just fails that one statement and lets you
        // keep going. Since this loop must keep processing the remaining
        // withdrawals after skipping a duplicate, each attempt runs inside
        // its own SAVEPOINT so only that one claim gets rolled back on
        // failure, not the whole transaction.
        $ok = false;
        $conn->exec('SAVEPOINT prize_claim');
        try {
            $ok = $refStmt->execute(['reference' => $reference, 'amount' => $amount]);
            $conn->exec('RELEASE SAVEPOINT prize_claim');
        } catch (PDOException $ex) {
            $conn->exec('ROLLBACK TO SAVEPOINT prize_claim');
            $sqlState = $ex->errorInfo[0] ?? null;
            if ($sqlState === '23505') {
                $skipped[] = $w + ['reason' => 'already submitted'];
                continue;
            }
            throw new Exception('Could not reserve prize reference: ' . $ex->getMessage());
        }

        $insertStmt->execute([
            'amount' => $amount,
            'method' => $method,
            'account_number' => $accountNumber,
            'guest_note' => $guestNote
        ]);
        $insertedRow = $insertStmt->fetch();
        $requestId = (int)$insertedRow['id'];

        $submitted[] = [
            'lobby_id' => $lobbyId,
            'team_id' => $teamId,
            'amount' => $amount,
            'request_id' => $requestId
        ];
    }

    $conn->commit();

    // Best-effort Telegram notify (mirrors submit-withdraw-request.php) —
    // never allowed to fail the response.
    foreach ($submitted as $s) {
        $telegramText =
            "🟣 New GUEST Prize Withdraw Request\n" .
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
        'message' => count($submitted) . ' guest withdraw request(s) submitted.',
        'submitted' => $submitted,
        'skipped' => $skipped
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
