<?php
header('Content-Type: application/json');

$host = getenv('DB_HOST') ?: 'not-set';
$port = getenv('DB_PORT') ?: 61002;

$start = microtime(true);
$conn = @fsockopen($host, (int)$port, $errno, $errstr, 5);
$time = round(microtime(true) - $start, 2);

if ($conn) {
    fclose($conn);
    echo json_encode([
        "reachable" => true,
        "host" => $host,
        "port" => $port,
        "time_seconds" => $time
    ]);
} else {
    echo json_encode([
        "reachable" => false,
        "host" => $host,
        "port" => $port,
        "error" => $errstr,
        "errno" => $errno,
        "time_seconds" => $time
    ]);
}
?>
