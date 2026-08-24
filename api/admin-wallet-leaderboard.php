<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
    exit;
}

// ---- Verify the requesting admin with Supabase ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/auth/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json'
    ]
]);
$supabaseResponse = curl_exec($ch);

if ($supabaseResponse === false) {
    curl_close($ch);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not verify user']);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User verification failed']);
    exit;
}

$requester = json_decode($supabaseResponse, true);
if (!is_array($requester) || empty($requester['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user data from Supabase']);
    exit;
}

$requesterId = $requester['id'];

// ---- Check admin/owner status ----
$ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?id=eq.' . urlencode($requesterId) . '&select=is_admin,is_owner');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]
]);
$profileResponse = curl_exec($ch);
$profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($profileResponse === false || $profileHttpCode < 200 || $profileHttpCode >= 300) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Could not verify admin status']);
    exit;
}

$profileRows = json_decode($profileResponse, true);
$profile = (is_array($profileRows) && count($profileRows) > 0) ? $profileRows[0] : null;
$isAdmin = $profile && (!empty($profile['is_admin']) || !empty($profile['is_owner']));

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// ---- Query wallet_users, highest balance first ----
$conn->set_charset('utf8mb4');

$rows = [];
$result = $conn->query("SELECT supabase_uid, email, balance FROM wallet_users ORDER BY balance DESC");

if ($result === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed', 'error' => $conn->error]);
    exit;
}

while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'uid' => $row['supabase_uid'],
        'email' => $row['email'],
        'balance' => (float)$row['balance']
    ];
}

// ---- Look up full_name for every row from Supabase profiles, in one batched call ----
// (Fetching one row at a time here would mean one extra Supabase call per leaderboard
// row, which gets slow/flaky once the list grows. A single "email=in.(...)" request
// gets the same full_name data in one round trip.)
$namesByEmail = [];

$emails = [];
foreach ($rows as $r) {
    if ($r['email'] !== '' && $r['email'] !== null) {
        $emails[$r['email']] = true;
    }
}
$emails = array_keys($emails);

// Supabase/PostgREST URLs have a practical length limit, so chunk large lists.
$chunks = array_chunk($emails, 150);

foreach ($chunks as $chunk) {
    $encoded = array_map('rawurlencode', $chunk);
    $inList = implode(',', $encoded);

    $ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?email=in.(' . $inList . ')&select=email,full_name');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $supabaseAnonKey,
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]
    ]);
    $namesResponse = curl_exec($ch);
    $namesHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($namesResponse !== false && $namesHttpCode >= 200 && $namesHttpCode < 300) {
        $nameRows = json_decode($namesResponse, true);
        if (is_array($nameRows)) {
            foreach ($nameRows as $nr) {
                if (!empty($nr['email'])) {
                    $namesByEmail[$nr['email']] = isset($nr['full_name']) ? $nr['full_name'] : null;
                }
            }
        }
    }
    // If a chunk fails, its users just fall back to null full_name below —
    // the leaderboard still returns instead of failing outright.
}

// ---- Assemble final ranked list ----
$users = [];
$rank = 0;
foreach ($rows as $r) {
    $rank++;
    $users[] = [
        'uid' => $r['uid'],
        'email' => $r['email'],
        'full_name' => $namesByEmail[$r['email']] ?? null,
        'balance' => $r['balance'],
        'rank' => $rank
    ];
}

echo json_encode([
    'success' => true,
    'users' => $users
]);

exit;
