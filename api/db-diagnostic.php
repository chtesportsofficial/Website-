<?php
// TEMPORARY CHTEO wallet DB diagnostic. Delete this file after troubleshooting.
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin:*');

function out($d,$s=200){
  while(ob_get_level()>0) ob_end_clean();
  http_response_code($s);
  echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

require_once __DIR__.'/../db.php';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
  out(['success'=>false,'stage'=>'db','message'=>'Database connection failed'],500);
}

$table = $conn->query("SHOW TABLES LIKE 'wallet_users'");
if (!$table || $table->num_rows===0) {
  out(['success'=>false,'stage'=>'table','message'=>'wallet_users table not found']);
}

$cols=[];
$r=$conn->query("SHOW COLUMNS FROM wallet_users");
if($r) while($row=$r->fetch_assoc()) $cols[]=$row['Field'];

$required=['id','supabase_uid','email','balance','withdrawable_balance','non_withdrawable_balance'];
$missing=array_values(array_diff($required,$cols));

$count=0;
$c=$conn->query("SELECT COUNT(*) AS n FROM wallet_users");
if($c){ $x=$c->fetch_assoc(); $count=(int)$x['n']; }

out([
  'success'=>empty($missing),
  'stage'=>empty($missing)?'database':'columns',
  'message'=>empty($missing)?'Database and wallet_users table are working':'Required columns are missing',
  'wallet_users_rows'=>$count,
  'missing_columns'=>$missing,
  'found_columns'=>$cols
]);
?>