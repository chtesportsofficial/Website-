<?php

header('Content-Type: application/json; charset=utf-8');

require_once "../db.php";

/*
|--------------------------------------------------------------------------
| Supabase Configuration
|--------------------------------------------------------------------------
*/

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';


/*
|--------------------------------------------------------------------------
| Referral Resolution Helper
|--------------------------------------------------------------------------
| Takes a referral code like "#2" (the number is profiles.user_number),
| looks up that profile's Supabase auth UUID via the Supabase REST API
| (using the service key, since this runs before any wallet_users row
| exists for the new user), then maps that UUID to a wallet_users.id.
| Returns null if the code is missing/malformed, the profile doesn't
| exist, or the referrer has no wallet_users row yet.
*/

function resolveReferrerWalletId($referralCode, $supabaseUrl, $serviceKey, $conn) {

    if (empty($referralCode) || empty($serviceKey)) {
        return null;
    }

    if (!preg_match('/^#(\d+)$/', trim($referralCode), $m)) {
        return null;
    }

    $userNumber = (int)$m[1];

    $ch = curl_init(
        $supabaseUrl . '/rest/v1/profiles?user_number=eq.' . $userNumber . '&select=id'
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return null;
    }

    $rows = json_decode($resp, true);

    if (!is_array($rows) || empty($rows[0]['id'])) {
        return null;
    }

    $referrerSupabaseUid = $rows[0]['id'];

    $stmt = $conn->prepare(
        "SELECT id FROM wallet_users WHERE supabase_uid = ? LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $referrerSupabaseUid);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    $stmt->close();

    return $row ? (int)$row['id'] : null;
}


/*
|--------------------------------------------------------------------------
| Get Authorization Header
|--------------------------------------------------------------------------
*/

$headers = function_exists('getallheaders') ? getallheaders() : [];

$authorization =
    $headers['Authorization']
    ?? $headers['authorization']
    ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase access token missing'
    ]);

    exit;
}

$accessToken = trim($matches[1]);


/*
|--------------------------------------------------------------------------
| Get User From Supabase
|--------------------------------------------------------------------------
*/

$ch = curl_init($supabaseUrl . '/auth/v1/user');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseAnonKey,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],

    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);

if ($response === false) {

    $curlError = curl_error($ch);

    curl_close($ch);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase connection failed',
        'error' => $curlError
    ]);

    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);


if ($httpCode !== 200) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid Supabase session',
        'supabase_http_code' => $httpCode
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Decode Supabase User
|--------------------------------------------------------------------------
*/

$user = json_decode($response, true);

if (
    !$user ||
    empty($user['id']) ||
    empty($user['email'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid Supabase user data'
    ]);

    exit;
}


$supabaseUid = $user['id'];
$email       = $user['email'];


/*
|--------------------------------------------------------------------------
| Get User Name
|--------------------------------------------------------------------------
*/

$name = '';

if (
    isset($user['user_metadata']) &&
    is_array($user['user_metadata'])
) {

    if (!empty($user['user_metadata']['full_name'])) {
        $name = $user['user_metadata']['full_name'];
    }

    elseif (!empty($user['user_metadata']['name'])) {
        $name = $user['user_metadata']['name'];
    }
}


/*
|--------------------------------------------------------------------------
| Get Referral Code (only matters for brand-new users, resolved below)
|--------------------------------------------------------------------------
*/

$referralCode = '';

if (
    isset($user['user_metadata']) &&
    is_array($user['user_metadata']) &&
    !empty($user['user_metadata']['referral'])
) {
    $referralCode = $user['user_metadata']['referral'];
}


/*
|--------------------------------------------------------------------------
| MySQL Connection Check
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'MySQL connection is not available'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Find Existing Wallet User
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id, balance, role, status
     FROM wallet_users
     WHERE supabase_uid = ? OR email = ?
     LIMIT 1"
);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database prepare failed',
        'error' => $conn->error
    ]);

    exit;
}

$stmt->bind_param(
    "ss",
    $supabaseUid,
    $email
);

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Existing User
|--------------------------------------------------------------------------
*/

if ($row) {

    $walletId = (int)$row['id'];

    $balance = (float)$row['balance'];

    $role = !empty($row['role'])
        ? $row['role']
        : 'user';

    $status = !empty($row['status'])
        ? $row['status']
        : 'active';


    /*
    | Update Supabase UID / Name / Email
    */

    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET supabase_uid = ?,
             name = ?,
             email = ?
         WHERE id = ?"
    );

    if ($stmt) {

        $stmt->bind_param(
            "sssi",
            $supabaseUid,
            $name,
            $email,
            $walletId
        );

        $stmt->execute();

        $stmt->close();
    }

}


/*
|--------------------------------------------------------------------------
| New User
|--------------------------------------------------------------------------
*/

else {

    $balance = 0.00;

    $role = 'user';

    $status = 'active';

    // Resolve the referral code (e.g. "#2") to the referrer's
    // wallet_users.id, if possible. Only relevant here since
    // referred_by is set once at creation and never changed after.
    $referrerWalletId = resolveReferrerWalletId(
        $referralCode,
        $supabaseUrl,
        getenv('SUPABASE_SERVICE_KEY'),
        $conn
    );


    $stmt = $conn->prepare(
        "INSERT INTO wallet_users
        (
            supabase_uid,
            name,
            email,
            password,
            balance,
            bonus_balance,
            referred_by,
            role,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            NULL,
            0.00,
            0.00,
            ?,
            'user',
            'active'
        )"
    );


    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Database prepare failed',
            'error' => $conn->error
        ]);

        exit;
    }


    $stmt->bind_param(
        "sssi",
        $supabaseUid,
        $name,
        $email,
        $referrerWalletId
    );


    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Could not create wallet account',
            'error' => $stmt->error
        ]);

        $stmt->close();

        exit;
    }


    $walletId = (int)$stmt->insert_id;

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| Check Account Status
|--------------------------------------------------------------------------
*/

if ($status !== 'active') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Wallet account is not active',
        'status' => $status
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Success
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,

    'message' => 'User synced successfully',

    'user' => [
        'id' => $walletId,
        'supabase_uid' => $supabaseUid,
        'email' => $email,
        'name' => $name,
        'balance' => $balance,
        'role' => $role,
        'status' => $status
    ]
]);

exit;

?>
