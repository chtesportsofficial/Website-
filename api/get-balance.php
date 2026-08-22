<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once "../db.php";

$uid = isset($_GET['uid']) ? trim($_GET['uid']) : '';

if ($uid === '') {
    echo json_encode([
        "success" => false,
        "message" => "UID required"
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id, balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Database query error"
    ]);
    exit;
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$stmt->bind_result($user_id, $balance);

if ($stmt->fetch()) {
    echo json_encode([
        "success" => true,
        "user_id" => $user_id,
        "balance" => (float)$balance
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "User not found",
        "balance" => 0
    ]);
}

$stmt->close();
?>
