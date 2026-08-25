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

// ---- Parse filter / sort / pagination params ----
// min_balance / max_balance: numeric range filter on balance
// uid: partial match search on supabase_uid (this is what admins search by,
// since it's the one identifier that's always present and unique)
// sort: 'desc' (default, highest first) or 'asc'
// page / limit: pagination, so we never pull thousands of rows into PHP/browser at once

$minBalance = isset($data['min_balance']) && $data['min_balance'] !== '' ? (float)$data['min_balance'] : null;
$maxBalance = isset($data['max_balance']) && $data['max_balance'] !== '' ? (float)$data['max_balance'] : null;
$uidSearch = isset($data['uid']) ? trim((string)$data['uid']) : '';
$uidSearch = ltrim($uidSearch, '#'); // admins see/type ids like "#33", strip the "#" before matching
$sort = (isset($data['sort']) && strtolower((string)$data['sort']) === 'asc') ? 'asc' : 'desc';

// ---- Resolve a "#33"-style user_number search into the real supabase_uid ----
// wallet_users.supabase_uid is the Supabase Auth UUID, NOT the same value as the
// short sequential id (profiles.user_number) admins see elsewhere in the panel.
// So a search has to go: user_number -> profiles.id (UUID) -> wallet_users.supabase_uid.
$uidSearchActive = false;
$matchingSupabaseUids = [];

if ($uidSearch !== '') {
    $uidSearchActive = true;
    if (is_numeric($uidSearch)) {
        $ch = curl_init(rtrim($supabaseUrl, '/') . '/rest/v1/profiles?user_number=eq.' . urlencode($uidSearch) . '&select=id');
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
        $matchResponse = curl_exec($ch);
        $matchHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($matchResponse !== false && $matchHttpCode >= 200 && $matchHttpCode < 300) {
            $matchRows = json_decode($matchResponse, true);
            if (is_array($matchRows)) {
                foreach ($matchRows as $mr) {
                    if (!empty($mr['id'])) {
                        $matchingSupabaseUids[] = $mr['id'];
                    }
                }
            }
        }
    }
    // Non-numeric input (or nothing found above) simply means no matches -
    // handled below by the "1 = 0" fallback, so the query still runs safely.
}

$page = isset($data['page']) ? (int)$data['page'] : 1;
if ($page < 1) { $page = 1; }

$limit = isset($data['limit']) ? (int)$data['limit'] : 50;
if ($limit < 1) { $limit = 50; }
if ($limit > 200) { $limit = 200; } // hard cap so a bad request can't force-load everything
$offset = ($page - 1) * $limit;

$conn->set_charset('utf8mb4');

// ---- Build WHERE clause + bound params (shared by the count query and the page query) ----
$whereParts = [];
$paramTypes = '';
$paramValues = [];

if ($minBalance !== null) {
    $whereParts[] = 'balance >= ?';
    $paramTypes .= 'd';
    $paramValues[] = $minBalance;
}
if ($maxBalance !== null) {
    $whereParts[] = 'balance <= ?';
    $paramTypes .= 'd';
    $paramValues[] = $maxBalance;
}
if ($uidSearchActive) {
    if (!empty($matchingSupabaseUids)) {
        $placeholders = implode(',', array_fill(0, count($matchingSupabaseUids), '?'));
        $whereParts[] = "supabase_uid IN ($placeholders)";
        foreach ($matchingSupabaseUids as $sid) {
            $paramTypes .= 's';
            $paramValues[] = $sid;
        }
    } else {
        // No profile with that user_number -> no possible match, return empty results
        // instead of accidentally matching everything.
        $whereParts[] = '1 = 0';
    }
}

$whereSql = count($whereParts) > 0 ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// ---- Overall stats (always unfiltered, so the summary card stays a true total) ----
$totalWallets = 0;
$totalBalanceAll = 0.0;
$overallResult = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(balance),0) AS total FROM wallet_users");
if ($overallResult !== false) {
    $overallRow = $overallResult->fetch_assoc();
    $totalWallets = (int)$overallRow['cnt'];
    $totalBalanceAll = (float)$overallRow['total'];
}

// ---- Count of rows matching the current filter (for pagination / "load more") ----
$totalMatching = 0;
$countSql = "SELECT COUNT(*) AS cnt FROM wallet_users $whereSql";
$countStmt = $conn->prepare($countSql);
if ($countStmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed', 'error' => $conn->error]);
    exit;
}
if ($paramTypes !== '') {
    $countStmt->bind_param($paramTypes, ...$paramValues);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
if ($countRes) {
    $totalMatching = (int)$countRes->fetch_assoc()['cnt'];
}
$countStmt->close();

// ---- Page of results, each with its rank against the FULL unfiltered leaderboard ----
// (rank is computed with a correlated subquery scoped to just this page's rows, not
// the whole table, so it stays cheap even with thousands of wallet_users rows)
$orderDir = $sort === 'asc' ? 'ASC' : 'DESC';
$rankCompare = $sort === 'asc' ? '<' : '>';

$pageSql = "
    SELECT w1.supabase_uid, w1.email, w1.balance,
        (SELECT COUNT(*) FROM wallet_users w2 WHERE w2.balance $rankCompare w1.balance) + 1 AS rnk
    FROM wallet_users w1
    $whereSql
    ORDER BY w1.balance $orderDir
    LIMIT ? OFFSET ?
";

$pageTypes = $paramTypes . 'ii';
$pageValues = $paramValues;
$pageValues[] = $limit;
$pageValues[] = $offset;

$pageStmt = $conn->prepare($pageSql);
if ($pageStmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed', 'error' => $conn->error]);
    exit;
}
$pageStmt->bind_param($pageTypes, ...$pageValues);
$pageStmt->execute();
$pageResult = $pageStmt->get_result();

if ($pageResult === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed', 'error' => $conn->error]);
    exit;
}

$rows = [];
$rankByUid = [];
while ($row = $pageResult->fetch_assoc()) {
    $rows[] = [
        'uid' => $row['supabase_uid'],
        'email' => $row['email'],
        'balance' => (float)$row['balance']
    ];
    $rankByUid[$row['supabase_uid']] = (int)$row['rnk'];
}
$pageStmt->close();

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

// ---- Assemble final list, using each row's true leaderboard rank ----
$users = [];
foreach ($rows as $r) {
    $users[] = [
        'uid' => $r['uid'],
        'email' => $r['email'],
        'full_name' => $namesByEmail[$r['email']] ?? null,
        'balance' => $r['balance'],
        'rank' => $rankByUid[$r['uid']] ?? null
    ];
}

echo json_encode([
    'success' => true,
    'users' => $users,
    'page' => $page,
    'limit' => $limit,
    'total_matching' => $totalMatching,
    'has_more' => ($offset + count($rows)) < $totalMatching,
    'total_wallets' => $totalWallets,
    'total_balance' => $totalBalanceAll
]);

exit;
