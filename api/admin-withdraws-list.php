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

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$accessToken = isset($data['access_token']) ? trim($data['access_token']) : '';
$status = isset($data['status']) ? trim($data['status']) : 'pending';

// The DB stores 'rejected' (see admin-review-withdraw.php), but the UI
// tab is labelled/keyed as 'rejected' too, so no translation needed —
// just make sure only known values are ever used in the query below.
if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $status = 'pending';
}

if ($accessToken === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Access token missing']);
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

/* Check admin/owner. */
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

/* Fetch requests. */
$conn->set_charset('utf8mb4');

$stmt = $conn->prepare(
    "SELECT id, user_id, email, amount, method, account_number, status, admin_note, created_at, reviewed_at
     FROM wallet_withdraw_requests
     WHERE status = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param('s', $status);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = [
        'id' => (int)$row['id'],
        'supabase_uid' => $row['user_id'],
        'email' => $row['email'],
        'amount' => (float)$row['amount'],
        'method' => $row['method'],
        'account_number' => $row['account_number'],
        'status' => $row['status'],
        'admin_note' => $row['admin_note'],
        'created_at' => $row['created_at'],
        'reviewed_at' => $row['reviewed_at']
    ];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'requests' => $requests
]);
?>
