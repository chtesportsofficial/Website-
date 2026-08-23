<?php
// create-charge.php
// Deposit শুরু করার endpoint — user amount পাঠালে PipraPay-তে একটা charge তৈরি করে
// এবং checkout URL ফেরত দেয়। wallet_transactions টেবিলে একটা 'pending' row রাখে।

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // চাইলে নির্দিষ্ট domain দিয়ে সীমিত করে দিন
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

// ব্রাউজার আসল request পাঠানোর আগে একটা OPTIONS "preflight" request পাঠায়।
// সেটাকে সাথে সাথে 200/204 দিয়ে শেষ করে দিতে হবে, নাহলে "Failed to fetch" আসে।
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require 'db.php'; // $conn এখান থেকে আসবে

// ---- 1. Input নিন ----
$data = json_decode(file_get_contents('php://input'), true);

$supabase_uid = $data['supabase_uid'] ?? null; // frontend থেকে logged-in user এর supabase uid পাঠাতে হবে
$amount       = $data['amount'] ?? null;

if (!$supabase_uid || !$amount || !is_numeric($amount) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'supabase_uid এবং valid amount দিতে হবে']);
    exit;
}

// ---- 2. wallet_users থেকে ইউজার খুঁজুন (balance সহ) ----
$stmt = $conn->prepare("SELECT id, email, name, balance FROM wallet_users WHERE supabase_uid = ?");
$stmt->bind_param("s", $supabase_uid);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'এই supabase_uid এর কোনো wallet user পাওয়া যায়নি']);
    exit;
}

$current_balance = $user['balance'] ?? 0;

// ---- 3. একটা unique order id বানান ----
$order_id = 'DEP-' . time() . '-' . $user['id'];

// ---- 4. PipraPay কে charge create request পাঠান ----
$piprapay_api_key = getenv('PIPRAPAY_API_KEY');

// ⚠️ DEBUG ONLY — test করার পর এই লাইনটা মুছে দিন
if (!$piprapay_api_key) {
    http_response_code(500);
    echo json_encode(['error' => 'PIPRAPAY_API_KEY সেট করা নেই', 'env_check' => getenv('PIPRAPAY_API_KEY') === false ? 'not found' : 'empty string']);
    exit;
}

// DEBUG debug লাইন সরানো হয়েছে
// সব possible header format try করছি
$headers_to_try = [
    ["accept: application/json", "content-type: application/json", "mh-piprapay-api-key: " . $piprapay_api_key],
    ["accept: application/json", "content-type: application/json", "Authorization: Bearer " . $piprapay_api_key],
    ["accept: application/json", "content-type: application/json", "X-API-KEY: " . $piprapay_api_key],
    ["accept: application/json", "content-type: application/json", "api-key: " . $piprapay_api_key],
];

$debug_results = [];
foreach ($headers_to_try as $headers) {
    $ch = curl_init("https://chteo-wallet-piprapay-1.onrender.com/api/create-charge");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(["full_name" => "Test", "email_mobile" => "test@test.com", "amount" => "10", "redirect_url" => "https://example.com", "cancel_url" => "https://example.com", "webhook_url" => "https://example.com", "currency" => "BDT", "return_type" => "POST"]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // header name টা বের করি (শেষ header টা দেখি)
    $last_header = end($headers);
    $header_name = explode(':', $last_header)[0];
    $debug_results[$header_name] = ['http_code' => $code, 'response' => json_decode($resp, true)];
}

echo json_encode($debug_results, JSON_PRETTY_PRINT);
exit;

$payload = [
    "full_name"    => $user['name'] ?: 'CHTEO User',
    "email_mobile" => $user['email'],
    "amount"       => (string)$amount,
    "metadata"     => ["order_id" => $order_id, "user_id" => $user['id']],
    "redirect_url" => "https://chtesportsofficial.github.io/Website-/deposit-success.html",
    "cancel_url"   => "https://chtesportsofficial.github.io/Website-/deposit-cancel.html",
    "webhook_url"  => "https://chteo-api.onrender.com/piprapay-webhook.php", // Step 6 এ বানাবেন (db.php-এর পাশেই, root এ)
    "return_type"  => "POST",
    "currency"     => "BDT"
];

// আপনার নিজস্ব self-hosted PipraPay ইনস্টলেশন (sandbox.piprapay.com বলে কিছু নেই — PipraPay self-hosted)
$piprapay_endpoint = "https://chteo-wallet-piprapay-1.onrender.com/api/create-charge";

$ch = curl_init($piprapay_endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "accept: application/json",
        "content-type: application/json",
        "mh-piprapay-api-key: " . $piprapay_api_key
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'PipraPay এ request পাঠানো যায়নি', 'details' => $curl_err]);
    exit;
}

$pp_response = json_decode($response, true);

// ⚠️ চেক করুন: PipraPay-এর আসল response এ checkout URL এর key exactly কী নামে আসে
$checkout_url = $pp_response['checkout_url']
    ?? $pp_response['payment_url']
    ?? $pp_response['pp_url']
    ?? null;

$pp_id = $pp_response['pp_id'] ?? $pp_response['id'] ?? null;

if ($http_code !== 200 || !$checkout_url || !$pp_id) {
    http_response_code(502);
    echo json_encode([
        'error' => 'PipraPay charge তৈরি ব্যর্থ হয়েছে',
        'http_code' => $http_code,
        'raw_response' => $pp_response
    ]);
    exit;
}

// ---- 5. wallet_transactions এ pending row রাখুন ----
// user_id, type, amount, balance_before, balance_after, reference (=pp_id), description (=order_id), status
// balance এখনো বদলায়নি তাই balance_before = balance_after = current_balance;
// webhook (Step 6) confirm হলে এই row আপডেট হয়ে balance_after বদলাবে ও balance যোগ হবে।
$type = 'deposit';
$status = 'pending';

$stmt2 = $conn->prepare(
    "INSERT INTO wallet_transactions
        (user_id, type, amount, balance_before, balance_after, reference, description, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
);
$stmt2->bind_param(
    "isdddsss",
    $user['id'],
    $type,
    $amount,
    $current_balance,
    $current_balance,
    $pp_id,
    $order_id,
    $status
);
$stmt2->execute();
$stmt2->close();

// ---- 6. Frontend কে checkout URL ফেরত দিন ----
echo json_encode([
    'success'      => true,
    'checkout_url' => $checkout_url,
    'pp_id'        => $pp_id,
    'order_id'     => $order_id
]);

$conn->close();
