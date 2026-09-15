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

require_once __DIR__ . '/db.php';       // exposes $conn (PDO, Postgres)
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
$stmt->execute([$status]);

$requests = [];
while ($row = $stmt->fetch()) {
    $requests[] = $row;
}

// The admin panel should show the same "UID" the user sees in the app
// (profiles.user_number in Supabase), not the internal wallet_users.id.
// Resolve wallet_users.id -> supabase_uid -> profiles.user_number.
if (!empty($requests)) {
    $walletToSupabase = [];
    $referredByMap = []; // wallet_users.id => referrer's wallet_users.id (or null)
    $uniqueIds = array_values(array_unique(array_column($requests, 'user_id')));

    // Single batched query instead of one query per unique user_id — the
    // earlier per-row loop was the main reason the list felt slow.
    $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
    $lookupStmt = $conn->prepare("SELECT id, supabase_uid, referred_by FROM wallet_users WHERE id IN ($placeholders)");
    $lookupStmt->execute($uniqueIds);
    while ($row = $lookupStmt->fetch()) {
        $walletToSupabase[(int)$row['id']] = $row['supabase_uid'];
        $referredByMap[(int)$row['id']] = $row['referred_by'] !== null ? (int)$row['referred_by'] : null;
    }

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

    // is_first_deposit: mirrors admin-deposits-review.php's own check exactly
    // (see the "Is this the depositor's FIRST ever approved deposit?" block
    // there) — it counts rows in referral_commissions for the depositor,
    // NOT approved wallet_deposit_requests or wallet_transactions rows.
    // That also means it only ever matters for a REFERRED user: if
    // wallet_users.referred_by is null, no commission or bonus fires either
    // way on approval, so we mark those false too (nothing to flag).
    $commissionCountByUser = []; // referred_id => count
    $referredIds = array_values(array_filter($uniqueIds, function ($uid) use ($referredByMap) {
        return !empty($referredByMap[$uid]);
    }));
    if (!empty($referredIds)) {
        $refPlaceholders = implode(',', array_fill(0, count($referredIds), '?'));
        $commissionStmt = $conn->prepare(
            "SELECT referred_id, COUNT(*) AS cnt FROM referral_commissions
             WHERE referred_id IN ($refPlaceholders) GROUP BY referred_id"
        );
        $commissionStmt->execute($referredIds);
        while ($row = $commissionStmt->fetch()) {
            $commissionCountByUser[(int)$row['referred_id']] = (int)$row['cnt'];
        }
    }

    // Walk oldest-first so that, when a referred user has several pending
    // requests in this list with zero prior commission rows, only the
    // earliest one claims the "first deposit" slot — the rest are flagged
    // as repeats so admin doesn't approve two 10% bonuses for the same user.
    $byAge = $requests;
    usort($byAge, function ($a, $b) { return strcmp($a['created_at'], $b['created_at']); });

    $firstSlotClaimed = [];
    $isFirstMap = [];
    foreach ($byAge as $r) {
        $uid = (int)$r['user_id'];

        if (empty($referredByMap[$uid])) {
            $isFirstMap[$r['id']] = false; // not referred — commission tier is moot
            continue;
        }

        $priorCount = $commissionCountByUser[$uid] ?? 0;
        if ($priorCount > 0) {
            $isFirstMap[$r['id']] = false;
            continue;
        }

        if (empty($firstSlotClaimed[$uid])) {
            $firstSlotClaimed[$uid] = true;
            $isFirstMap[$r['id']] = true;
        } else {
            $isFirstMap[$r['id']] = false;
        }
    }

    foreach ($requests as &$r) {
        $r['is_first_deposit'] = $isFirstMap[$r['id']] ?? false;
    }
    unset($r);
}

echo json_encode(['success' => true, 'requests' => $requests]);
