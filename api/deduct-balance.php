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

if ($amount <= 0) {
    echo json_encode(["success" => false, "message" => "A positive amount is required"]);
    exit;
}

// ---- 4. Deduct, inside a transaction with a row lock (prevents double-spend
//         from two simultaneous requests racing each other)
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT id, balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param("s", $verifiedUid);
    $stmt->execute();
    $stmt->bind_result($user_id, $balance);
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

    $newBalance = (float)$balance - $amount;

    $upd = $conn->prepare("UPDATE wallet_users SET balance = ? WHERE id = ?");
    $upd->bind_param("di", $newBalance, $user_id);
    $upd->execute();
    $upd->close();

    $ins = $conn->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference, description, status) VALUES (?, 'debit', ?, ?, ?, ?, ?, 'completed')");
    $ins->bind_param("iddsss", $user_id, $amount, $balance, $newBalance, $reference, $description);
    $ins->execute();
    $ins->close();

    $conn->commit();
    echo json_encode(["success" => true, "balance" => (float)$newBalance]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
