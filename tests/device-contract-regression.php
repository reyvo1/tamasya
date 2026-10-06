<?php
declare(strict_types=1);
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/090_security_cluster_smartlock.php';
$cases=[null,[],['success'=>true],['success'=>'true','jobId'=>'job-a','status'=>'completed'],['success'=>true,'jobId'=>'job-b','status'=>'completed'],['success'=>true,'jobId'=>'job-a','status'=>'accepted'],['success'=>false,'jobId'=>'job-a','status'=>'completed']];
foreach($cases as $case)if(tamasyaSmartLockAckComplete($case,'job-a'))throw new RuntimeException('Unconfirmed device response accepted.');
if(!tamasyaSmartLockAckComplete(['success'=>true,'jobId'=>'job-a','status'=>'completed'],'job-a'))throw new RuntimeException('Valid ACK rejected.');
$wire=tamasyaSmartLockBridgeRequest(['jobId'=>'job-a','idempotencyKey'=>'job-a','action'=>'revoke','actor'=>['password'=>'private']]);
if(isset($wire['actor'])||$wire['jobId']!==$wire['idempotencyKey'])throw new RuntimeException('Device payload contract mismatch.');
echo "Device contract assertions passed: 9\n";
