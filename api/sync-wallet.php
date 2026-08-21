<?php

header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


// ======================================================
// DATABASE
// ======================================================

require_once __DIR__ . '/../db.php';


// ======================================================
// SUPABASE CONFIG
// ======================================================

$supabaseUrl = 'https://myfficbwcbgbxbdqjexv.supabase.co';

$supabaseAnonKey = 'sb_publishable__j8qkCkEOMtdymJnYpfceA_sscwkH_5';


// ======================================================
// ONLY POST
// ======================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'POST request required'
    ]);

    exit;
}


// ======================================================
// READ JSON BODY
// ======================================================

$rawInput = file_get_contents('php://input');

$data = json_decode($rawInput, true);


// ======================================================
// GET ACCESS TOKEN
// ======================================================

$accessToken = '';

if (is_array($data) && !empty($data['access_token'])) {

    $accessToken = trim($data['access_token']);

}


// Also support normal form POST
if ($accessToken === '' && !empty($_POST['access_token'])) {

    $accessToken = trim($_POST['access_token']);

}


// ======================================================
// CHECK TOKEN
// ======================================================

if ($accessToken === '') {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase access token missing'
    ]);

    exit;
}


// ======================================================
// VERIFY USER WITH SUPABASE
// ======================================================

$ch = curl_init(
    rtrim($supabaseUrl, '/') . '/auth/v1/user'
);

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

    $curlError = curl_error($ch);

    curl_close($ch);

    http_response_code(502);

    echo json_encode([
        'success' => false,
        'message' => 'Could not connect to Supabase',
        'error' => $curlError
    ]);

    exit;
}


$supabaseHttpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);


// ======================================================
// SUPABASE RESPONSE CHECK
// ======================================================

if (
    $supabaseHttpCode < 200 ||
    $supabaseHttpCode >= 300
) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase user verification failed',
        'http_code' => $supabaseHttpCode,
        'supabase_response' => json_decode(
            $supabaseResponse,
            true
        )
    ]);

    exit;
}


// ======================================================
// DECODE SUPABASE USER
// ======================================================

$user = json_decode(
    $supabaseResponse,
    true
);


if (
    !is_array($user) ||
    empty($user['id']) ||
    empty($user['email'])
) {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Supabase returned invalid user data'
    ]);

    exit;
}


$supabaseUid = trim($user['id']);

$email = trim($user['email']);


// ======================================================
// GET USER NAME
// ======================================================

$name = '';

if (
    isset($user['user_metadata']) &&
    is_array($user['user_metadata'])
) {

    if (
        !empty($user['user_metadata']['full_name'])
    ) {

        $name = trim(
            $user['user_metadata']['full_name']
        );

    } elseif (
        !empty($user['user_metadata']['name'])
    ) {

        $name = trim(
            $user['user_metadata']['name']
        );
    }
}


// Fallback name
if ($name === '') {

    $name = explode('@', $email)[0];

}


// ======================================================
// MYSQL CONNECTION CHECK
// ======================================================

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'MySQL connection is not available'
    ]);

    exit;
}


$conn->set_charset('utf8mb4');


// ======================================================
// FIND USER
// ======================================================

$stmt = $conn->prepare(
    "SELECT
        id,
        supabase_uid,
        name,
        email,
        balance,
        role,
        status
     FROM wallet_users
     WHERE supabase_uid = ?
        OR email = ?
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
    'ss',
    $supabaseUid,
    $email
);


if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database query failed',
        'error' => $error
    ]);

    exit;
}


$result = $stmt->get_result();

$existingUser = $result->fetch_assoc();

$stmt->close();


// ======================================================
// EXISTING USER
// ======================================================

if ($existingUser) {

    $walletId = (int)$existingUser['id'];

    $balance = (float)$existingUser['balance'];

    $role = !empty($existingUser['role'])
        ? $existingUser['role']
        : 'user';

    $status = !empty($existingUser['status'])
        ? $existingUser['status']
        : 'active';


    // Update latest Supabase information

    $stmt = $conn->prepare(
        "UPDATE wallet_users
         SET
            supabase_uid = ?,
            name = ?,
            email = ?
         WHERE id = ?"
    );


    if ($stmt) {

        $stmt->bind_param(
            'sssi',
            $supabaseUid,
            $name,
            $email,
            $walletId
        );

        $stmt->execute();

        $stmt->close();
    }

}


// ======================================================
// NEW USER
// ======================================================

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
            'message' => 'Insert prepare failed',
            'error' => $conn->error
        ]);

        exit;
    }


    $stmt->bind_param(
        'sss',
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
            'message' => 'Wallet user INSERT failed',
            'error' => $error
        ]);

        exit;
    }


    $walletId = (int)$stmt->insert_id;

    $stmt->close();
}


// ======================================================
// CHECK ACCOUNT STATUS
// ======================================================

if ($status !== 'active') {

    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Wallet account is not active',
        'status' => $status
    ]);

    exit;
}


// ======================================================
// SUCCESS
// ======================================================

echo json_encode([

    'success' => true,

    'message' => 'Wallet connected successfully',

    'user' => [

        'id' => $walletId,

        'supabase_uid' => $supabaseUid,

        'name' => $name,

        'email' => $email,

        'balance' => $balance,

        'role' => $role,

        'status' => $status

    ]

]);

exit;

?>