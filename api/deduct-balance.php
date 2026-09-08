<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../db.php";

// ---- 1. Read the bearer token the frontend sends (the user's own Supabase
//         session access_token) — never trust a client-supplied uid directly.
$authHeader = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authHeader = $value; break; }
    }
} elseif (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authHeader = $value; break; }
    }
}
if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    echo json_encode(["success" => false, "message" => "Missing auth token"]);
    exit;
}
$accessToken = trim($m[1]);

// ---- 2. Verify the token with Supabase Auth itself (this is what makes it
//         safe — Supabase checks the token's signature/expiry and hands back
//         the REAL user it belongs to; we never trust anything the client typed).
$SUPABASE_URL = "https://myfficbwcbgbxbdqjexv.supabase.co";
$SUPABASE_ANON_KEY = "sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5";

$ch = curl_init("$SUPABASE_URL/auth/v1/user");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $accessToken",
        "apikey: $SUPABASE_ANON_KEY"
    ],
    CURLOPT_TIMEOUT => 10
]);
$authRes = curl_exec($ch);
$authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$authUser = json_decode($authRes, true);
if ($authHttpCode !== 200 || empty($authUser['id'])) {
    echo json_encode(["success" => false, "message" => "Invalid or expired session"]);
    exit;
}
$verifiedUid = $authUser['id']; // <-- the ONLY uid we trust from here on

// ---- 3. Read the request body
$input = json_decode(file_get_contents('php://input'), true);
$amount = isset($input['amount']) ? (float)$input['amount'] : 0;
$reference = isset($input['reference']) ? trim($input['reference']) : '';
$description = isset($input['description']) ? trim($input['description']) : 'Tournament entry fee';
$slotTimes = isset($input['slot_times']) && is_array($input['slot_times']) ? $input['slot_times'] : [];

if ($amount <= 0) {
    echo json_encode(["success" => false, "message" => "A positive amount is required"]);
    exit;
}

// ---- 4. Deduct, inside a transaction with a row lock (prevents double-spend
//         from two simultaneous requests racing each other)
$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "SELECT id, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users WHERE supabase_uid = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param("s", $verifiedUid);
    $stmt->execute();
    $stmt->bind_result($user_id, $balance, $withdrawableBalance, $nonWithdrawableBalance);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Wallet not found for this account"]);
        exit;
    }

    if ((float)$balance < $amount) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Insufficient balance", "balance" => (float)$balance]);
        exit;
    }

    // Tournament fees can use the whole wallet. Consume non-withdrawable
    // (deposit) funds first, then withdrawable (prize) funds, so prize
    // money isn't spent before deposit money is used up. Keeps the two
    // balances and total in sync.
    $fromNonWithdrawable = min((float)$nonWithdrawableBalance, $amount);
    $fromWithdrawable = $amount - $fromNonWithdrawable;
    $newNonWithdrawable = (float)$nonWithdrawableBalance - $fromNonWithdrawable;
    $newWithdrawable = (float)$withdrawableBalance - $fromWithdrawable;
    $newBalance = $newWithdrawable + $newNonWithdrawable;

    $upd = $conn->prepare(
        "UPDATE wallet_users
         SET withdrawable_balance = ?,
             non_withdrawable_balance = ?,
             balance = ?
         WHERE id = ?"
    );
    $upd->bind_param("dddi", $newWithdrawable, $newNonWithdrawable, $newBalance, $user_id);
    $upd->execute();
    $upd->close();

    $ins = $conn->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference, description, status) VALUES (?, 'debit', ?, ?, ?, ?, ?, 'completed')");
    $ins->bind_param("iddsss", $user_id, $amount, $balance, $newBalance, $reference, $description);
    $ins->execute();
    $ins->close();

    $conn->commit();

    // ---- 5. Notify admin on Telegram, routed to the group for THIS
    //         purchase's slot time(s) (best-effort — never breaks the
    //         actual payment response if Telegram is down/misconfigured).
    notifyTelegramSlotPurchase($authUser['email'] ?? $verifiedUid, $amount, $reference, $slotTimes);

    echo json_encode(["success" => true, "balance" => (float)$newBalance, "withdrawable_balance" => (float)$newWithdrawable, "non_withdrawable_balance" => (float)$newNonWithdrawable]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}

// ---- Telegram notification (routed by slot time) ----
// 1. Message @BotFather on Telegram, send /newbot, follow the prompts —
//    it gives you a token like "123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxxx".
//    (Same bot as your existing deposit/withdraw notify bot works fine —
//    one bot can be a member of many groups.)
// 2. Add that bot to each "SLOT UPDATES" group (one per time slot).
// 3. In each group, send any message (e.g. "hi"), then visit this URL in a
//    browser (replace <TOKEN>): https://api.telegram.org/bot<TOKEN>/getUpdates
//    — find that group's "chat":{"id": ...} in the response, that's its CHAT_ID.
// 4. Paste the token below, and each group's chat id next to its time.
//    Leave BOT_TOKEN empty to disable this entirely without touching the
//    rest of the file. A time left as '' just skips that group silently.
function notifyTelegramSlotPurchase($who, $amount, $reference, $slotTimes) {
    $BOT_TOKEN = '8946675932:AAHxGR-v1JoGVDmpKJYnpqriKpF7swjSKkE'; // <-- paste your bot token here (shared by all groups)
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

    // "9:00 PM" -> 9, "12:00 AM" -> 12 — matches how the time slots are
    // named everywhere else on the site (tournament-details.html, lobby
    // creation in admin), so no extra config needed beyond the chat ids above.
    $hours = [];
    foreach ($slotTimes as $t) {
        if (preg_match('/^(\d{1,2}):/', trim((string)$t), $m)) {
            $hours[(int)$m[1]] = true; // dedupe: a multi-slot purchase touching the same hour only notifies once
        }
    }

    foreach (array_keys($hours) as $hour) {
        if (empty($CHAT_ID_BY_HOUR[$hour])) continue; // that hour's group not configured — skip quietly
        $chatId = $CHAT_ID_BY_HOUR[$hour];
        $text = "🎮 New slot purchase — {$hour}:00\n"
              . "User: {$who}\n"
              . "Amount: ৳{$amount}\n"
              . "{$reference}";
        $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text]),
            CURLOPT_TIMEOUT => 5, // don't let a slow/unreachable Telegram delay the payment response
        ]);
        curl_exec($ch); // response intentionally ignored — this must never fail the purchase
        curl_close($ch);
    }
}
