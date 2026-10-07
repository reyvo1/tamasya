<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/030_booking_finance.php';
function tamasyaDecodeBookingExtras($extras){return is_array($extras)?$extras:json_decode($extras??'[]',true);}
$passed=0;
function checkNego($ok,$label){global $passed;if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";}
function rejectNego(callable $f,$label){try{$f();}catch(InvalidArgumentException|RuntimeException $e){checkNego(true,$label);return;}throw new RuntimeException($label);}
$booking=['totalAmount'=>660000,'vatAmount'=>60000,'vatRate'=>10,'extras'=>[
 ['id'=>'extension1','total'=>220000,'price'=>220000,'qty'=>1,'baseAmount'=>200000,'taxAmount'=>20000,'taxRate'=>10,'taxKind'=>'extension','allocationType'=>'room'],
 ['id'=>'service1','total'=>110000,'price'=>110000,'qty'=>1,'baseAmount'=>100000,'taxAmount'=>10000,'taxRate'=>10,'allocationType'=>'extra']]];
$c=[['key'=>'room','allocationType'=>'room','gross'=>330000,'tax'=>30000,'rate'=>10],['key'=>'extra:extension1','allocationType'=>'room','gross'=>220000,'tax'=>20000,'rate'=>10],['key'=>'extra:service1','allocationType'=>'extra','gross'=>110000,'tax'=>10000,'rate'=>10]];
$paid=['room'=>330000,'extra:extension1'=>0,'extra:service1'=>0];
$p=tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,550000);
checkNego($p['discountAmount']===110000.0&&$p['totalAmount']===550000.0,'Paid original room, unpaid extension discounted');
checkNego(!isset($p['componentCuts']['room'])&&$p['extras'][0]['total']===110000.0,'Paid room preserved, extension snapshot reduced');
checkNego($p['extras'][1]===$booking['extras'][1],'Service charge metadata and tax unchanged');
checkNego($p['vatAmount']===50000.0&&$p['extras'][0]['taxAmount']===10000.0,'PBJT reduced on unpaid extension at its original snapshot');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,400000),'Cannot discount paid room or service even when final exceeds paid total');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,300000),'Cannot reduce final below cash received');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,660000),'Unchanged price rejected');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,770000),'Price increase rejected by discount flow');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,NAN),'NaN rejected');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,INF),'Infinity rejected');
rejectNego(fn()=>tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,0),'Zero total rejected');
$p=tamasyaNegotiatedPricePlan($booking,$c,$paid,330000,440000);
checkNego($p['extras'][0]['total']===0.0&&$p['vatAmount']===40000.0,'Unpaid extension can be waived while paid room and service remain');
$paid=['room'=>100000,'extra:extension1'=>0,'extra:service1'=>50000];
$p=tamasyaNegotiatedPricePlan($booking,$c,$paid,150000,300000.01);
checkNego(abs(array_sum(array_column($p['componentCuts'],'amount'))-359999.99)<0.001,'Cent allocation across multiple unpaid components is exact');
foreach($c as $line)if($line['allocationType']==='room')checkNego($line['gross']-($p['componentCuts'][$line['key']]['amount']??0)>=($paid[$line['key']]??0)-0.001,'Each room component keeps its paid floor '.$line['key']);
$booking['extras'][0]['taxRate']=0;$booking['extras'][0]['taxAmount']=0;$booking['extras'][0]['baseAmount']=220000;$booking['vatAmount']=40000;$booking['vatRate']=null;$c[1]['tax']=0;$c[1]['rate']=0;
$p=tamasyaNegotiatedPricePlan($booking,$c,['room'=>330000],330000,550000);
checkNego($p['vatAmount']===40000.0&&$p['vatRate']===null&&$p['extras'][0]['taxAmount']===0.0,'Mixed PBJT preserves original zero-tax extension and nullable rate');
// Repeated quotes cannot compound a reduction silently; only explicit fresh totals apply.
$booking=['totalAmount'=>100.01,'vatAmount'=>9.09,'vatRate'=>10,'extras'=>[]];$c=[['key'=>'room','allocationType'=>'room','gross'=>100.01,'tax'=>9.09,'rate'=>10]];
for($i=1;$i<=100;$i++){$target=$i/100;$p=tamasyaNegotiatedPricePlan($booking,$c,[],0,$target);checkNego(abs($p['totalAmount']-$target)<0.001&&$p['vatAmount']>=0&&$p['vatAmount']<=$p['totalAmount']+0.001,'Small amounts preserve tax bounds '.$i);}
echo "$passed checks passed\n";
