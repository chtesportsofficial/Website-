<?php

// SECURITY: no hardcoded fallback values here anymore. This file used to
// fall back to a real (now-exposed) DB host/user/password/name if the
// Render environment variables were missing. That meant anyone who saw
// this file in the public repo had working DB credentials, and any
// misconfiguration would silently connect somewhere insecure instead of
// failing. Now it fails loudly instead — every value MUST come from a
// Render Environment Variable (Render dashboard -> your service ->
// Environment -> Add Environment Variable):
//   DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT (optional, defaults to 3306)

$required = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];
$missing = [];
foreach ($required as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        $missing[] = $key;
    }
}
if (!empty($missing)) {
    error_log('db.php: missing required environment variable(s): ' . implode(', ', $missing));
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => 'Server misconfigured (missing DB environment variables). Contact admin.'
    ]));
}

$host     = getenv('DB_HOST');
$user     = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$dbname   = getenv('DB_NAME');
$port     = getenv('DB_PORT') ?: 3306;

$conn = new mysqli($host, $user, $password, $dbname, (int)$port);

if ($conn->connect_error) {
    error_log('db.php: DB connection failed: ' . $conn->connect_error);
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed. Contact admin.'
    ]));
}

$conn->set_charset("utf8mb4");

?>
