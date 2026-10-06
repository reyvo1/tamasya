<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_CONTROL_CONFIG_RAW',true);require __DIR__.'/delivery.php';
$mode=$argv[1]??'once';if(!in_array($mode,['once','daemon'],true))throw new InvalidArgumentException('Use once or daemon.');
do{try{$config=tamasyaHqConfig();echo tamasyaHybridJson(tamasyaHqDeliverOnce(tamasyaHqPdo($config),$config))."\n";}catch(Throwable $e){fwrite(STDERR,"DELIVERY_WORKER_FAILED\n");if($mode==='once')exit(1);}if($mode==='daemon')sleep(5);}while($mode==='daemon');
