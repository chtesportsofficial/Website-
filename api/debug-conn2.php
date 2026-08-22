<?php
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: 'not-set';
$user = getenv('DB_USER') ?: 'not-set';
$pass = getenv('DB_PASSWORD') ?: 'not-set';
$db   = getenv('DB_NAME') ?: 'not-set';
$port = getenv('DB_PORT') ?: 61002;

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 8);

$start = microtime(true);
$ok = @mysqli_real_connect($conn, $host, $user, $pass, $db, (int)$port);
$time = round(microtime(true) - $start, 2);

if ($ok) {
    echo json_encode(["success" => true, "time_seconds" => $time]);
} else {
    echo json_encode([
        "success" => false,
        "error" => mysqli_connect_error(),
        "errno" => mysqli_connect_errno(),
        "time_seconds" => $time
    ]);
}
?>
