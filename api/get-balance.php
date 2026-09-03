<?php
// Wallet balance endpoint — returns JSON only.
// Authenticates the Supabase access token, then reads the user's own
// wallet_users row. It never trusts a client-supplied UID.
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

function wallet_json($data, $status = 200) {
    if (ob_get_level() > 0) { ob_clean(); }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    wallet_json(['success' => true]);
}

// Convert PHP warnings/notices into JSON instead of corrupting the response.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    wallet_json([
        'success' => false,
        'message' => 'Server error while loading wallet balance.'
    ], 500);
});

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../admin-auth.php';

    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);
    if (!is_array($input)) $input = [];

    $access_token = trim((string)($input['access_token'] ?? ''));

    // Also accept Authorization: Bearer <token> as a fallback.
    if ($access_token === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authorization = $headers['Authorization']
            ?? $headers['authorization']
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $m)) {
            $access_token = trim($m[1]);
        }
    }

    if ($access_token === '') {
        wallet_json([
            'success' => false,
            'message' => 'Supabase access token missing'
        ], 401);
    }

    $uid = verify_user_token($access_token);
    if (!$uid) {
        wallet_json([
            'success' => false,
            'message' => 'Not logged in or session expired. Please sign in again.'
        ], 401);
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        wallet_json([
            'success' => false,
            'message' => 'MySQL connection is not available'
        ], 500);
    }

    $stmt = $conn->prepare(
        'SELECT id, balance, withdrawable_balance, non_withdrawable_balance
         FROM wallet_users WHERE supabase_uid = ? LIMIT 1'
    );

    if (!$stmt) {
        wallet_json([
            'success' => false,
            'message' => 'Database query error'
        ], 500);
    }

    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $stmt->bind_result($user_id, $balance, $withdrawableBalance, $nonWithdrawableBalance);

    if ($stmt->fetch()) {
        $stmt->close();
        wallet_json([
            'success' => true,
            'user_id' => (int)$user_id,
            'balance' => (float)$balance,
            'withdrawable_balance' => (float)$withdrawableBalance,
            'non_withdrawable_balance' => (float)$nonWithdrawableBalance
        ]);
    }

    $stmt->close();
    wallet_json([
        'success' => false,
        'message' => 'Wallet user not found',
        'balance' => 0,
        'withdrawable_balance' => 0,
        'non_withdrawable_balance' => 0
    ], 404);

} catch (Throwable $e) {
    wallet_json([
        'success' => false,
        'message' => 'Unable to load wallet balance.'
    ], 500);
}
