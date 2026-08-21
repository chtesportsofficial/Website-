<?php

$host = getenv('DB_HOST') ?: 'sql312.infinityfree.com';
$user = getenv('DB_USER') ?: 'if0_42695077';
$password = getenv('DB_PASSWORD') ?: 'h2jEoDfBkjiI';
$dbname = getenv('DB_NAME') ?: 'if0_42695077_cht';

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
