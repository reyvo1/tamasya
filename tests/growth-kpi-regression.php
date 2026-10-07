<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/hr_staff/020_identity_access_audit.php';
require dirname(__DIR__).'/api/modules/growth_enterprise_locked/105_growth_suite.php';
$passed=0;
function checkKpi(bool $ok,string $name):void{global $passed;if(!$ok)throw new RuntimeException($name);$passed++;echo "PASS $name\n";}
$today=new DateTimeImmutable('2026-10-07');$end=new DateTimeImmutable('2026-10-08');
$b=['status'=>'active','checkIn'=>'2026-10-05','checkOut'=>'2026-10-06','isOpenEnded'=>0,'totalAmount'=>330000,'extraCharge'=>0];
$w=tamasyaGrowthStayWindow($b,$end,$today);
checkKpi($w['to']->format('Y-m-d')==='2026-10-08'&&$w['nights']===3,'Active overdue stay includes tonight without altering scheduled date or money');
checkKpi(tamasyaGrowthOverlapNights($w['from'],$w['to'],$today,$end)===1,'Today KPI cannot drop an active overdue room');
checkKpi($b['checkOut']==='2026-10-06'&&$b['totalAmount']===330000,'Read projection never extends billing or mutates source');
$b['checkOut']='2026-10-07';$b['checkoutDueAt']='2026-10-07 12:00:00';$w=tamasyaGrowthStayWindow($b,$end,new DateTimeImmutable('2026-10-07 11:00:00'));checkKpi($w['to']->format('Y-m-d')==='2026-10-07','Active guest before scheduled checkout does not invent tonight');$w=tamasyaGrowthStayWindow($b,$end,new DateTimeImmutable('2026-10-07 13:00:00'));checkKpi($w['to']->format('Y-m-d')==='2026-10-08','Active guest past scheduled checkout remains occupied tonight');
$b['status']='completed';$b['checkOut']='2026-10-10';$b['actualCheckOutAt']='2026-10-05 12:00:00';$b['checkIn']='2026-10-01';
$w=tamasyaGrowthStayWindow($b,$end,$today);
checkKpi(tamasyaGrowthOverlapNights($w['from'],$w['to'],$today,$end)===0,'Early checkout before period produces zero nights, not absolute date difference');
checkKpi(tamasyaGrowthOverlapNights($w['from'],$w['to'],new DateTimeImmutable('2026-10-03'),new DateTimeImmutable('2026-10-05'))===2,'Early checkout retains only nights actually overlapping history');
$b['actualCheckOutAt']='2026-10-07 12:00:00';$w=tamasyaGrowthStayWindow($b,$end,$today);
checkKpi(tamasyaGrowthOverlapNights($w['from'],$w['to'],$today,$end)===0,'Checkout day is exclusive for completed overnight stay');
$b['status']='active';$b['actualCheckOutAt']=null;$b['isOpenEnded']=1;$b['checkOut']='2026-10-02';$horizon=new DateTimeImmutable('2026-10-21');$w=tamasyaGrowthStayWindow($b,$horizon,$today);
checkKpi($w['to']==$horizon&&tamasyaGrowthOverlapNights($w['from'],$w['to'],$today,$horizon)===14,'Open-ended active stay remains in entire forecast horizon');
$b['status']='cancelled';checkKpi(tamasyaGrowthStayWindow($b,$end,$today)===null,'Cancelled booking has no occupied nights');
$b['status']='reserved';$b['checkIn']='2026-02-30';checkKpi(tamasyaGrowthStayWindow($b,$end,$today)===null,'Invalid real calendar date is excluded');
$b['checkIn']='2026-10-08';$b['checkOut']='2026-10-07';checkKpi(tamasyaGrowthStayWindow($b,$end,$today)===null,'Reversed dates are not repaired by inventing nights');
$b=['totalAmount'=>660000,'discountAmount'=>10000,'roomCharge'=>330000,'extraCharge'=>330000,'extras'=>[['id'=>'extension','total'=>220000,'allocationType'=>'room','taxKind'=>'extension'],['id'=>'service','total'=>110000,'allocationType'=>'extra']]];
$p=tamasyaGrowthBookingCharges($b);
checkKpi($p['roomNet']===540000.0&&$p['services']===110000.0,'Stale stored classification cannot turn room extension into service');
checkKpi($p['roomGross']+$p['services']-$p['discount']===$p['netTotal']&&$p['netTotal']===650000.0,'Enterprise folio source honors canonical discount once');
checkKpi(tamasyaGrowthBookingCharges(['totalAmount'=>500000,'extraCharge'=>100000,'extras'=>null])['roomNet']===400000.0,'Legacy service snapshot survives absent extras');
checkKpi(tamasyaGrowthBookingCharges(['totalAmount'=>110000,'extras'=>[['total'=>110000,'allocationType'=>'extra']]])['roomNet']===0.0,'Pure service charge never becomes room revenue fallback');
$src=file_get_contents(dirname(__DIR__).'/api/modules/growth_enterprise_locked/107_enterprise_completion.php');
checkKpi(substr_count($src,'tamasyaGrowthBookingCharges(')>=2&&str_contains($src,'tamasyaGrowthStayWindow('),'Enterprise forecast and folio both use shared canonical read projection');
echo "$passed checks passed\n";
