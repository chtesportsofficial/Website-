<?php
// piprapay-webhook.php
// ধাপ ১: PipraPay থেকে প্রকৃত webhook payload কেমন আসে সেটা লগ করার জন্য —
// এই ভার্সনে এখনো balance credit করা হচ্ছে না, শুধু raw data capture করা হচ্ছে।
// একবার real payload দেখে ফেললে এটাকে আপডেট করে verify + credit লজিক বসানো হবে।

header('Content-Type: application/json');

// ---- 1. Raw body ও headers ধরুন ----
$raw_body = file_get_contents('php://input');
$headers  = function_exists('getallheaders') ? getallheaders() : [];

// ---- 2. Render-এর log-এ লিখে রাখুন (Render dashboard > Logs এ দেখা যাবে) ----
error_log('===== PIPRAPAY WEBHOOK HIT =====');
error_log('METHOD: ' . $_SERVER['REQUEST_METHOD']);
error_log('HEADERS: ' . json_encode($headers));
error_log('RAW BODY: ' . $raw_body);
error_log('GET PARAMS: ' . json_encode($_GET));
error_log('POST PARAMS: ' . json_encode($_POST));
error_log('=================================');

// ---- 3. চেষ্টা করে দেখুন JSON হিসেবে parse হয় কিনা (শুধু নিজেদের বোঝার জন্য) ----
$decoded = json_decode($raw_body, true);
if ($decoded) {
    error_log('DECODED JSON: ' . print_r($decoded, true));
}

// ---- 4. সবসময় 200 রিটার্ন করুন যাতে PipraPay বারবার retry না করে ----
http_response_code(200);
echo json_encode(['received' => true]);
