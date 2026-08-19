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


    $stmt = $conn->prepare(
        "INSERT INTO wallet_users
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
        "sss",
        $supabaseUid,
        $name,
        $email
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