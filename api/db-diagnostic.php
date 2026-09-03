<?php
// Temporary wallet DB diagnostic. Remove after troubleshooting.
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function out($data,$status=200){
    while(ob_get_level()>0) ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') out(['success'=>true]);

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../supabase-config.php';

$raw=file_get_contents('php://input');
$input=json_decode($raw?:'{}',true);
if(!is_array($input)) $input=[];
$token=trim((string)($input['access_token']??''));
if(!$token && !empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.+)/i',$_SERVER['HTTP_AUTHORIZATION'],$m)) $token=trim($m[1]);
if(!$token) out(['success'=>false,'stage'=>'auth','message'=>'Access token missing'],401);

$ctx=stream_context_create(['http'=>[
    'method'=>'GET','timeout'=>12,'ignore_errors'=>true,
    'header'=>"apikey: ".SUPABASE_ANON_KEY."\r\nAuthorization: Bearer ".$token."\r\nAccept: application/json\r\n"
]]);
$resp=@file_get_contents(SUPABASE_URL.'/auth/v1/user',false,$ctx);
$status=0;
if(!empty($http_response_header)) foreach($http_response_header as $h){ if(preg_match('#^HTTP/\\S+\\s+(\\d+)#',$h,$m)){ $status=(int)$m[1]; break; }}
$user=json_decode((string)$resp,true);
if($status!==200 || !is_array($user) || empty($user['id'])) out(['success'=>false,'stage'=>'auth','message'=>'Invalid or expired session','supabase_http_code'=>$status],401);
$uid=(string)$user['id'];

if(!isset($conn) || !($conn instanceof mysqli)) out(['success'=>false,'stage'=>'db_connection','message'=>'mysqli connection unavailable'],500);

$checks=[];
$checks['connection']= ['ok'=>($conn->ping()===true)];

$r=$conn->query("SHOW TABLES LIKE 'wallet_users'");
$checks['wallet_users_table']=['ok'=>($r!==false && $r->num_rows>0)];
if($r) $r->free();

$needed=['id','supabase_uid','email','balance','withdrawable_balance','non_withdrawable_balance'];
$columns=[];
$r=$conn->query("SHOW COLUMNS FROM wallet_users");
if($r){ while($row=$r->fetch_assoc()) $columns[]=$row['Field']; $r->free(); }
$checks['columns']=[];
foreach($needed as $c) $checks['columns'][$c]=in_array($c,$columns,true);

if($checks['wallet_users_table']['ok']){
    $stmt=$conn->prepare('SELECT id,balance,withdrawable_balance,non_withdrawable_balance FROM wallet_users WHERE supabase_uid = ? LIMIT 1');
    if(!$stmt){
        $checks['user_query']=['ok'=>false,'error'=>$conn->error];
    } else {
        $stmt->bind_param('s',$uid);
        if(!$stmt->execute()){
            $checks['user_query']=['ok'=>false,'error'=>$stmt->error];
        } else {
            $stmt->bind_result($id,$balance,$withdrawable,$nonWithdrawable);
            if($stmt->fetch()){
                $checks['user_query']=['ok'=>true,'user_id'=>(int)$id,'balance'=>(float)$balance,'withdrawable_balance'=>(float)$withdrawable,'non_withdrawable_balance'=>(float)$nonWithdrawable];
            } else {
                $checks['user_query']=['ok'=>true,'found'=>false,'message'=>'No wallet_users row for this Supabase UID'];
            }
        }
        $stmt->close();
    }
}

$all=true;
foreach($checks as $k=>$v){
    if($k==='columns'){ foreach($v as $ok) if(!$ok) $all=false; }
    elseif(isset($v['ok']) && !$v['ok']) $all=false;
}

out(['success'=>true,'database_ok'=>$all,'supabase_uid'=>$uid,'checks'=>$checks]);
