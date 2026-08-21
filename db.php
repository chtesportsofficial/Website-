<?php

$host = "sql312.infinityfree.com";
$user = "if0_42695077";
$password = "h2jEoDfBkjiI";
$dbname = "if0_42695077_cht";

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>