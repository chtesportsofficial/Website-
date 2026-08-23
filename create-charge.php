<?php
// create-charge.php
// Deposit শুরু করার endpoint — user amount পাঠালে PipraPay-তে একটা charge তৈরি করে
// এবং checkout URL ফেরত দেয়।

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // চাইলে নির্দিষ্ট domain দিয়ে সীমিত করে দিন
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST');

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

// ---- 2. wallet_users থেকে ইউজার খুঁজুন ----
$stmt = $conn->prepare("SELECT id, email, full_name FROM wallet_users WHERE supabase_uid = ?");
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

// ---- 3. একটা unique order id বানান ----
$order_id = 'DEP-' . time() . '-' . $user['id'];

// ---- 4. PipraPay কে charge create request পাঠান ----
$piprapay_api_key = getenv('PIPRAPAY_API_KEY'); // Render env var থেকে আসবে

if (!$piprapay_api_key) {
    http_response_code(500);
    echo json_encode(['error' => 'PIPRAPAY_API_KEY সেট করা নেই (Render env var চেক করুন)']);
    exit;
}

$payload = [
    "full_name"    => $user['full_name'] ?: 'CHTEO User',
    "email_mobile" => $user['email'],
    "amount"       => (string)$amount,
    "metadata"     => ["order_id" => $order_id, "wallet_user_id" => $user['id']],
    "redirect_url" => "https://chtesportsofficial.github.io/Website-/deposit-success.html",
    "cancel_url"   => "https://chtesportsofficial.github.io/Website-/deposit-cancel.html",
    "webhook_url"  => "https://chteo-api.onrender.com/api/piprapay-webhook.php", // Step 6 এ বানাবেন
    "return_type"  => "POST",
    "currency"     => "BDT"
];

// প্রথমে TEST করুন sandbox দিয়ে, পরে production URL এ বদলাবেন
$piprapay_endpoint = "https://sandbox.piprapay.com/api/create-charge";

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
// (এই কোডে সাধারণ সম্ভাব্য key গুলো ট্রাই করা হয়েছে: checkout_url / payment_url / pp_url)
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
// ⚠️ চেক করুন আপনার wallet_transactions টেবিলের কলাম নামগুলো এর সাথে মেলে কিনা
$stmt2 = $conn->prepare(
    "INSERT INTO wallet_transactions (wallet_user_id, amount, type, status, pp_id, order_id, created_at)
     VALUES (?, ?, 'deposit', 'pending', ?, ?, NOW())"
);
$stmt2->bind_param("idss", $user['id'], $amount, $pp_id, $order_id);
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
