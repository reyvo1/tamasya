<?php
// Read-only: exercise shipped financial helpers, no production DB required.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/030_booking_finance.php';
$pass=0;
function roomPriceAssert(bool $ok,string $label):void{global $pass;if(!$ok)throw new RuntimeException('FAIL: '.$label);$pass++;echo 'PASS '.$label."\n";}
$quote=static fn(float $price,int $nights,float $rate):float=>tamasyaPublishedRoomGrossTotal($price,$nights,$rate);
roomPriceAssert($quote(227273,1,10)===250000.0,'Published room Rp227273 + PBJT 10% = Rp250000, no Rp0.30');
roomPriceAssert($quote(227273,2,10)===500000.0,'Published nightly gross rounded BEFORE night count');
roomPriceAssert($quote(227273,3,10)===750000.0,'Published nightly quote consistent at 3 nights');
roomPriceAssert($quote(200000,1,0)===200000.0,'Zero PBJT unchanged');
roomPriceAssert($quote(227272.73,1,10)===250000.0,'Precise master base still yields advertised rupiah gross');
foreach([1,2,3] as $nights){
 $total=$quote(227273,$nights,10);$tax=calculateInclusiveTaxBreakdown($total,10);
 roomPriceAssert(abs($tax['baseAmount']+$tax['taxAmount']-$total)<0.00001,'Inclusive DPP+PBJT reconcile at '.$nights.' nights');
 if($nights===1)roomPriceAssert($tax['baseAmount']===227272.73&&$tax['taxAmount']===22727.27,'1-night DPP=227272.73, PBJT=22727.27');
}
$manual=250000.30;
$manualTax=calculateInclusiveTaxBreakdown($manual,10);
roomPriceAssert($manualTax['baseAmount']+$manualTax['taxAmount']===$manual,'Manual gross with legal fractional cents is NOT silently rounded');
foreach([[INF,1,10],[-1,1,10],[100,0,10],[100,1,-5],[1e12,2,10]] as [$base,$nights,$rate]){
 try{$quote((float)$base,(int)$nights,(float)$rate);throw new RuntimeException('Invalid input accepted');}catch(InvalidArgumentException $e){roomPriceAssert(true,'Invalid master quote fails closed');}
}
$root=dirname(__DIR__);
$multi=file_get_contents($root.'/api/modules/front_office/043_multi_room_reservations.php');
roomPriceAssert(str_contains($multi,'tamasyaPublishedRoomGrossTotal('),'Multi-room availability uses same quote helper');
$telegram=file_get_contents($root.'/api/routes/080_telegram_webhook.php');
roomPriceAssert(substr_count($telegram,'tamasyaPublishedRoomGrossTotal(')>=6,'Telegram six standard master-rate entry points use canonical quote helper');
$extension=file_get_contents($root.'/api/modules/comms/050_integrations_telegram_mail.php');
roomPriceAssert(str_contains($extension,'tamasyaPublishedRoomGrossTotal('),'Telegram extension master uses canonical quote helper');
echo "Published room nett PHP pricing: $pass passed; 0 failed.\n";
