<?php

// db.php — Supabase Postgres connection (Session Pooler, IPv4-safe).
// Migrated from filess.io MySQL after repeated filess.io outages
// (EHOSTUNREACH — the DB host itself became unreachable).
//
// All values MUST come from Render Environment Variables
// (Render dashboard -> your service -> Environment -> Add Environment Variable):
//   DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT (optional, defaults to 5432)
//
// Set these to the Supabase "Session pooler" values (Project Settings ->
// Database -> Connection pooling -> Session pooler):
//   DB_HOST     = aws-0-ap-southeast-2.pooler.supabase.com
//   DB_USER     = postgres.myfficbwcbgbxbdqjexv
//   DB_PASSWORD = <your Supabase database password>
//   DB_NAME     = postgres
//   DB_PORT     = 5432
//
// NOTE: this file now exposes $conn as a PDO object (Postgres), not a
// mysqli object. Every other PHP file that used mysqli-style calls
// ($conn->prepare(), bind_param(), bind_result(), $stmt->execute())
// needs to be updated to PDO style ($conn->prepare(), execute([...]),
// fetch()) — that is Step 4 of the migration, done file by file.

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
$port     = getenv('DB_PORT') ?: 5432;

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

try {
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('db.php: DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed. Contact admin.'
    ]));
}

?>
