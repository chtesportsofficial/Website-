<?php
// admin-deposits-list.php
// Called by admin.html to list deposit requests filtered by status.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';       // exposes $conn (mysqli)
require_once __DIR__ . '/admin-auth.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$access_token = $input['access_token'] ?? '';
$status = $input['status'] ?? 'pending';

if (!in_array($status, ['pending', 'approved', 'rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

$admin_uid = verify_admin_token($access_token);
if (!$admin_uid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied — admin only.']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT id, user_id, method, sender_number, trx_id, amount, status, admin_note, created_at, reviewed_at
     FROM wallet_deposit_requests
     WHERE status = ?
     ORDER BY created_at DESC
     LIMIT 200"
);
$stmt->bind_param('s', $status);
$stmt->execute();
$result = $stmt->get_result();

$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'requests' => $requests]);
$conn->close();
