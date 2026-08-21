<?php
header('Content-Type: application/json');

require_once "../db.php";

$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if ($email === '') {
    echo json_encode([
        "success" => false,
        "message" => "Email required"
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id, balance FROM wallet_users WHERE email = ? LIMIT 1");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Database query error"
    ]);
    exit;
}

$stmt->bind_param("s", $email);
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
