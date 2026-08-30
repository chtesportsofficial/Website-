<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';
$supabaseKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';

/*
|--------------------------------------------------------------------------
| Get Authorization header
|--------------------------------------------------------------------------
*/

$headers = function_exists('getallheaders') ? getallheaders() : [];

$authHeader = '';

foreach ($headers as $key => $value) {
    if (strtolower($key) === 'authorization') {
        $authHeader = trim($value);
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Fallback for some servers
|--------------------------------------------------------------------------
*/

if ($authHeader === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = trim($_SERVER['HTTP_AUTHORIZATION']);
}

if ($authHeader === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase access token missing'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Extract Bearer token
|--------------------------------------------------------------------------
*/

if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid Authorization header'
    ]);

    exit;
}

$accessToken = trim($matches[1]);

if ($accessToken === '') {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Empty access token'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Ask Supabase who is logged in
|--------------------------------------------------------------------------
*/

$ch = curl_init($supabaseUrl . '/auth/v1/user');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . $supabaseKey,
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);

if ($response === false) {
    $curlError = curl_error($ch);

    curl_close($ch);

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Could not connect to Supabase',
        'error' => $curlError
    ]);

    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

/*
|--------------------------------------------------------------------------
| Check Supabase response
|--------------------------------------------------------------------------
*/

if ($httpCode !== 200) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid Supabase session'
    ]);

    exit;
}

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

/*
|--------------------------------------------------------------------------
| Supabase user information
|--------------------------------------------------------------------------
*/

$supabaseUid = $user['id'];
$email       = $user['email'];

$name = '';

if (
    isset($user['user_metadata']) &&
    is_array($user['user_metadata'])
) {
    $name =
        $user['user_metadata']['full_name']
        ?? $user['user_metadata']['name']
        ?? '';
}

if ($name === '') {
    $name = explode('@', $email)[0];
}

/*
|--------------------------------------------------------------------------
| Find wallet user
|
| First try Supabase UID.
| If not found, try email.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT id, balance, role, status
    FROM wallet_users
    WHERE supabase_uid = ? OR email = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database query preparation failed'
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
| Existing wallet user
|--------------------------------------------------------------------------
*/

if ($row) {

    $walletId = (int)$row['id'];
    $balance  = (float)$row['balance'];
    $role     = $row['role'];
    $status   = $row['status'];

    /*
    |--------------------------------------------------------------
    | Make sure Supabase UID is linked
    |--------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE wallet_users
        SET
            supabase_uid = ?,
            name = ?,
            email = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

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
| New wallet user
|--------------------------------------------------------------------------
*/

else {

    $balance = 0.00;
    $role    = 'user';
    $status  = 'active';

    $stmt = $conn->prepare("
        INSERT INTO wallet_users
        (
            supabase_uid,
            name,
            email,
            password,
            balance,
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
            'user',
            'active'
        )
    ");

    if (!$stmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Could not prepare wallet account'
        ]);

        exit;
    }

    $stmt->bind_param(
        "sss",
        $supabaseUid,
        $name,
        $email
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Could not create wallet account',
            'error' => $error
        ]);

        exit;
    }

    $walletId = (int)$conn->insert_id;

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Check wallet status
|--------------------------------------------------------------------------
*/

if ($status !== 'active') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Wallet account is not active'
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
        'role' => $role
    ]
]);

?>