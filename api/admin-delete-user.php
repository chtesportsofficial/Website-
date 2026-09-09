<?php
// admin-delete-user.php
// Permanently deletes a user's login account from Supabase Auth.
// - Only callable by a verified admin/owner (verify_admin_token).
// - Does NOT touch lobby_teams / feedback / activity_logs rows — those
//   keep their history and just lose the link to this user (see
//   delete_user_fk_safety.sql, which must be run once beforehand so those
//   foreign keys are ON DELETE SET NULL instead of CASCADE).
// - Does NOT touch the MySQL wallet (wallet_users / wallet_transactions on
//   filess.io) — deposit/withdraw history there is untouched.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../admin-auth.php';

$input = json_decode(file_get_contents('php://input'), true);
$access_token = $input['access_token'] ?? null;
$target_user_id = $input['user_id'] ?? null;

$admin_uid = verify_admin_token($access_token);
if (!$admin_uid) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if (!$target_user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing user_id.']);
    exit;
}

if ($target_user_id === $admin_uid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
    exit;
}

// Safety: never allow deleting the owner account, even by another admin.
list($code, $rows) = supabase_curl(
    SUPABASE_URL . '/rest/v1/profiles?id=eq.' . urlencode($target_user_id) . '&select=is_owner,is_admin',
    [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
    ]
);
if ($code !== 200 || !is_array($rows) || count($rows) === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}
if (!empty($rows[0]['is_owner'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cannot delete the owner account.']);
    exit;
}

// Delete the auth user. If profiles.id has ON DELETE CASCADE to
// auth.users(id) (the standard Supabase setup), this also removes the
// profiles row automatically.
$ch = curl_init(SUPABASE_URL . '/auth/v1/admin/users/' . urlencode($target_user_id));
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . SUPABASE_SERVICE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 && $httpCode !== 204) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not delete the account.', 'debug' => $resp]);
    exit;
}

// Fallback: if profiles.id did NOT cascade-delete from auth.users (some
// projects have it as a plain FK, not cascade), remove the leftover
// profile row explicitly so it doesn't show up as a ghost account.
$ch2 = curl_init(SUPABASE_URL . '/rest/v1/profiles?id=eq.' . urlencode($target_user_id));
curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'apikey: ' . SUPABASE_SERVICE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    'Prefer: return=minimal'
]);
curl_exec($ch2);
curl_close($ch2);

// Flag the matching MySQL wallet row (filess.io) as deleted, so it drops
// out of the Wallet Leaderboard — but the row itself, and its
// deposit/withdraw history in wallet_transactions, stay intact.
// This is best-effort: if the wallet DB is unreachable for any reason,
// we still report success for the part that matters (the login account
// is gone), rather than confusingly failing an already-successful delete.
$walletFlagWarning = null;
try {
    $required = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];
    $missingEnv = [];
    foreach ($required as $key) {
        if (getenv($key) === false || getenv($key) === '') { $missingEnv[] = $key; }
    }
    if (!empty($missingEnv)) {
        throw new Exception('Missing DB env vars: ' . implode(', ', $missingEnv));
    }
    $mysqlConn = @new mysqli(
        getenv('DB_HOST'),
        getenv('DB_USER'),
        getenv('DB_PASSWORD'),
        getenv('DB_NAME'),
        (int)(getenv('DB_PORT') ?: 3306)
    );
    if ($mysqlConn->connect_error) {
        throw new Exception('DB connect failed: ' . $mysqlConn->connect_error);
    }
    $mysqlConn->set_charset('utf8mb4');
    $stmt = $mysqlConn->prepare('UPDATE wallet_users SET account_deleted = 1 WHERE supabase_uid = ?');
    $stmt->bind_param('s', $target_user_id);
    $stmt->execute();
    $stmt->close();
    $mysqlConn->close();
} catch (Throwable $e) {
    $walletFlagWarning = $e->getMessage();
    error_log('admin-delete-user.php: could not flag wallet_users as deleted — ' . $walletFlagWarning);
}

echo json_encode(['success' => true, 'message' => 'User deleted.', 'wallet_flag_warning' => $walletFlagWarning]);
