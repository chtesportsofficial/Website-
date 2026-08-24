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

// The admin panel should show the same "UID" the user sees in the app
// (profiles.user_number in Supabase), not the internal wallet_users.id.
// Resolve wallet_users.id -> supabase_uid -> profiles.user_number.
if (!empty($requests)) {
    $walletToSupabase = [];
    $lookupStmt = $conn->prepare("SELECT supabase_uid FROM wallet_users WHERE id = ? LIMIT 1");
    foreach (array_unique(array_column($requests, 'user_id')) as $uid) {
        $lookupStmt->bind_param('i', $uid);
        $lookupStmt->execute();
        $row = $lookupStmt->get_result()->fetch_assoc();
        if ($row) {
            $walletToSupabase[$uid] = $row['supabase_uid'];
        }
    }
    $lookupStmt->close();

    $supabaseToUserNumber = [];
    $supabaseUids = array_values(array_filter($walletToSupabase));
    if (!empty($supabaseUids)) {
        $idListForUrl = implode(',', $supabaseUids);
        list($code, $rows) = supabase_curl(
            SUPABASE_URL . '/rest/v1/profiles?id=in.(' . $idListForUrl . ')&select=id,user_number',
            [
                'apikey: ' . SUPABASE_SERVICE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
            ]
        );
        if ($code === 200 && is_array($rows)) {
            foreach ($rows as $r) {
                $supabaseToUserNumber[$r['id']] = $r['user_number'];
            }
        }
    }

    foreach ($requests as &$r) {
        $sUid = $walletToSupabase[$r['user_id']] ?? null;
        // Fall back to the internal id if the Supabase lookup fails for
        // any reason, so the panel still shows something usable.
        $r['display_uid'] = ($sUid && isset($supabaseToUserNumber[$sUid]))
            ? $supabaseToUserNumber[$sUid]
            : $r['user_id'];
        // user-detail.html looks up profiles by the raw Supabase UUID
        // (?uid=<uuid>), not the display user_number — expose it too.
        $r['supabase_uid'] = $sUid;
    }
    unset($r);
}

echo json_encode(['success' => true, 'requests' => $requests]);
$conn->close();
