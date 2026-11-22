<?php
declare(strict_types=1);
session_start(); header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(403); echo json_encode(['error'=>'CSRF failed']); exit; }
$input=json_decode(file_get_contents('php://input'),true) ?? [];
$map=['preflight'=>'preflight','start'=>'start','retry'=>'retry','counts'=>'validate-counts','checksum'=>'validate-checksum','final-sync'=>'final-sync'];
$a=$input['action']??'';
if(!isset($map[$a])){http_response_code(400);echo json_encode(['error'=>'Invalid action']);exit;}
if ($a === 'start') {
  $cmd = 'sudo -n /usr/local/sbin/nfs3-migrate-web-start 2>&1';
} else {
  $cmd = 'sudo -n /usr/local/sbin/nfs3-migrate '.$map[$a].' 2>&1';
}
exec($cmd,$out,$rc);
echo json_encode(['ok'=>$rc===0,'message'=>implode("\n",$out),'exit_code'=>$rc]);
