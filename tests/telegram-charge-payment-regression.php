<?php
// In-memory unit doubles only; no hotel DB, server or Telegram transport.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/comms/050_integrations_telegram_mail.php';
require dirname(__DIR__).'/api/modules/finance/018_canonical_business_policy.php';
require dirname(__DIR__).'/api/modules/hr_staff/058_employee_self_service.php';
require dirname(__DIR__).'/api/modules/hr_staff/020_identity_access_audit.php';
class TelegramDuplicateOperationException extends RuntimeException {}
$GLOBALS['chargeCalls']=[];
function applyCanonicalTelegramBookingChargeWorkflow($pdo,$actor,array $payload,string $operationId){
    if(isset($GLOBALS['chargeCalls'][$operationId]))throw new TelegramDuplicateOperationException('Duplicate');
    $GLOBALS['chargeCalls'][$operationId]=$payload;
    return ['amount'=>$payload['amount'],'newCheckOut'=>'2026-10-08'];
}
final class ChargeFixture extends PDO {
    public array $actor=['id'=>'operator','role'=>'admin','telegram_state'=>null,'telegram_context'=>null];
    public array $accounts=[['id'=>'bank1','name'=>'Transfer Account','type'=>'bank','isActive'=>1],['id'=>'qris1','name'=>'QRIS Account','type'=>'edc_qris','isActive'=>1],['id'=>'off','name'=>'Inactive','type'=>'bank','isActive'=>0],['id'=>'card','name'=>'EDC Card','type'=>'edc_card','isActive'=>1]];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new ChargeStatement($this,$query);}
}
final class ChargeStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private ChargeFixture $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(str_contains($this->sql,"telegram_state='waiting_for_charge_payment'")){$this->db->actor['telegram_state']='waiting_for_charge_payment';$this->db->actor['telegram_context']=$params[0];}
        if(str_contains($this->sql,'telegram_state=NULL')&&($params[1]??null)===$this->db->actor['telegram_context']){$this->db->actor['telegram_state']=null;$this->db->actor['telegram_context']=null;}
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{foreach($this->db->accounts as $a)if($a['id']===($this->params[0]??''))return $a;return false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return array_values(array_filter($this->db->accounts,fn($a)=>$a['isActive']===1&&$a['type']===($this->params[0]??'')));}
    public function fetchColumn(int $column=0):mixed{return ($this->fetch())['name']??false;}
}
$passed=0;
function tgCheck(bool $ok,string $name):void{global $passed;if(!$ok)throw new RuntimeException($name);$passed++;echo "PASS $name\n";}
function tgReject(callable $fn,string $name):void{$rejected=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$rejected=true;}tgCheck($rejected,$name);}
function callbacks(array $result):array{return array_column(array_merge(...$result['markup']['inline_keyboard']),'callback_data');}
$db=new ChargeFixture();
$booking=['id'=>'booking1','roomNumber'=>'28','status'=>'active','checkOut'=>'2026-10-07','totalAmount'=>300000];
$payload=['action'=>'extension','nights'=>1,'amount'=>110000.3,'quotedBaseAmount'=>100000.27];
foreach(['cash','transfer','qris'] as $method){
    $begin=tamasyaTelegramChargeBegin($db,$db->actor,$booking,$payload,'source-'.$method);
    $ctx=json_decode($db->actor['telegram_context'],true);$nonce=$ctx['nonce'];
    tgCheck(count($GLOBALS['chargeCalls'])===array_search($method,['cash','transfer','qris']),'Selecting paid status never posts a receipt: '.$method);
    foreach(['cash','transfer','qris'] as $choice)tgCheck(in_array('r_charge_method:'.$nonce.':'.$choice,callbacks($begin),true),'Explicit method available: '.$method.'/'.$choice);
    $selected=tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_method:'.$nonce.':'.$method);
    if($method!=='cash'){
        $id=$method==='qris'?'qris1':'bank1';
        tgCheck(in_array('r_charge_account:'.$nonce.':'.$id,callbacks($selected),true),'Correct account kind offered: '.$method);
        tgCheck(count(callbacks($selected))===3,'Wrong-kind, inactive and card accounts omitted: '.$method);
        tgReject(fn()=>tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_account:'.$nonce.':off'),'Inactive account rejected: '.$method);
        tgReject(fn()=>tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_account:'.$nonce.':'.($method==='qris'?'bank1':'qris1')),'Wrong account kind rejected: '.$method);
        $selected=tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_account:'.$nonce.':'.$id);
    }
    tgCheck(in_array('r_charge_save:'.$nonce,callbacks($selected),true),'Receipt requires final confirmation: '.$method);
    $actorBefore=$db->actor;
    $saved=tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_save:'.$nonce);
    $call=end($GLOBALS['chargeCalls']);
    tgCheck($call['paymentMethod']===$method&&($call['bankAccountId']??null)===($method==='cash'?null:($method==='qris'?'qris1':'bank1')),'Canonical workflow receives exact payment leg: '.$method);
    tgCheck($call['amount']===110000.3&&$call['bookingId']==='booking1'&&$call['expectedCheckOut']==='2026-10-07','Quote cents and booking identity preserved: '.$method);
    tgCheck($db->actor['telegram_state']===null&&$db->actor['telegram_context']===null,'Successful draft clears its own state: '.$method);
    tgReject(fn()=>tamasyaTelegramChargeHandle($db,$actorBefore,'r_charge_save:'.$nonce),'Same draft replay uses same operation ID: '.$method);
}
$begin=tamasyaTelegramChargeBegin($db,$db->actor,$booking,$payload,'guarded');$ctx=json_decode($db->actor['telegram_context'],true);$nonce=$ctx['nonce'];
tgReject(fn()=>tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_save:'.$nonce),'Missing payment selection never defaults to cash');
tgReject(fn()=>tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_method:wrong:cash'),'Old or mismatched draft rejected');
foreach(['owner','finance','cleaning_service'] as $role){$actor=$db->actor;$actor['role']=$role;tgReject(fn()=>tamasyaTelegramChargeHandle($db,$actor,'r_charge_method:'.$nonce.':cash'),'Role cannot pay extension: '.$role);}
$actor=$db->actor;$ctx['expiresAt']=1;$actor['telegram_context']=json_encode($ctx);tgReject(fn()=>tamasyaTelegramChargeHandle($db,$actor,'r_charge_method:'.$nonce.':cash'),'Expired draft rejected without posting');
$db->accounts=[];$none=tamasyaTelegramChargeHandle($db,$db->actor,'r_charge_method:'.$nonce.':qris');tgCheck(str_contains($none['text'],'Tidak ada akun')&&!in_array('r_charge_save:'.$nonce,callbacks($none),true),'No QRIS account never falls back to cash');
tgCheck(tamasyaTelegramChargeMoney(110000.3)==='Rp 110.000,3'&&tamasyaTelegramChargeMoney(0)==='Rp 0','Display keeps cents and zero');
$owner=['id'=>'owner','role'=>'owner'];tgReject(fn()=>tamasyaEmployeeCreateLeaveRequest($db,$owner,[],'owner-test','telegram'),'Owner cannot create leave through Telegram or web');tgReject(fn()=>tamasyaEmployeeCancelLeaveRequest($db,$owner,'leave1','','telegram'),'Owner cannot cancel leave through Telegram or web');
foreach(['250000'=>250000.0,'250.000'=>250000.0,'250.000,30'=>250000.3,'0'=>0.0,'Rp 0'=>0.0,'rp. 1.265.000,25'=>1265000.25,'1000000000000'=>1000000000000.0] as $input=>$value)tgCheck(tamasyaTelegramParseMoney((string)$input)===$value,'Strict money accepts correct amount: '.$input);
foreach(['-500','1.5','250000abc','1,234','0abc','1e6','1000000000001',''] as $input)tgCheck(tamasyaTelegramParseMoney($input)===null,'Strict money rejects altered or ambiguous digits: '.$input);
// Bound and unbound simulator responses use the real projection gate.
$GLOBALS['simulationProjectionCalls']=0;
function getRoleScopedHotelData($pdo,$staff){$GLOBALS['simulationProjectionCalls']++;return ['currentUser'=>['id'=>$staff['id'],'role'=>$staff['role']]];}
tgCheck(tamasyaTelegramSimulationHotelData($db,null)===null&&$GLOBALS['simulationProjectionCalls']===0,'Unbound Telegram simulator never reads hotel data or calls typed session projection');
tgCheck(tamasyaTelegramSimulationHotelData($db,[])===null&&$GLOBALS['simulationProjectionCalls']===0,'Empty Telegram identity cannot read hotel data');
// Execute the real identity lookup and projection together, including PDO's
// false result. Testing a hand-written null alone missed the R8 failure.
final class TelegramIdentityFixture extends PDO {
    public int $queries=0;
    public function __construct(public array|false $binding=false,public array $legacy=[]){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $this->queries++;
        return new TelegramIdentityStatement(str_contains($query,'telegram_bindings')?($this->binding?[$this->binding]:[]):$this->legacy);
    }
}
final class TelegramIdentityStatement extends PDOStatement {
    public function __construct(private array $rows){}
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
$identityDb=new TelegramIdentityFixture();
tgCheck(findActiveStaffByTelegramUserId($identityDb,'')===null&&$identityDb->queries===0,'Empty Telegram ID resolves to null without database lookup');
$unknownStaff=findActiveStaffByTelegramUserId($identityDb,'999001999');
tgCheck($unknownStaff===null&&$identityDb->queries===2,'Missing binding and legacy account resolve PDO false to null');
tgCheck(tamasyaTelegramSimulationHotelData($identityDb,$unknownStaff)===null&&$GLOBALS['simulationProjectionCalls']===0,'Real unknown identity passes typed projection without exposing hotel data');
$duplicateDb=new TelegramIdentityFixture(false,[['id'=>'a'],['id'=>'b']]);
tgCheck(findActiveStaffByTelegramUserId($duplicateDb,'900001999')===null,'Duplicate legacy Telegram ID remains unbound');
$verifiedStaff=['id'=>'bound-owner','role'=>'owner'];
$verifiedDb=new TelegramIdentityFixture($verifiedStaff,[['id'=>'legacy-admin','role'=>'admin']]);
tgCheck(findActiveStaffByTelegramUserId($verifiedDb,'900001001')===$verifiedStaff&&$verifiedDb->queries===1,'Verified binding keeps real role and wins over legacy identity');
$legacyStaff=['id'=>'legacy-operator','role'=>'receptionist'];
tgCheck(findActiveStaffByTelegramUserId(new TelegramIdentityFixture(false,[$legacyStaff]),'900001002')===$legacyStaff,'Unique active legacy binding remains compatible');
$simData=tamasyaTelegramSimulationHotelData($db,['id'=>'bound-operator','role'=>'receptionist']);
tgCheck($simData['currentUser']['role']==='receptionist'&&$GLOBALS['simulationProjectionCalls']===1,'Bound simulator reads the resolved staff scope, preserving its real role');
$webhook=file_get_contents(dirname(__DIR__).'/api/routes/080_telegram_webhook.php');
tgCheck(substr_count($webhook,'"db" => tamasyaTelegramSimulationHotelData($pdo, $loggedInStaff)')===2,'Message and callback simulators both use the guarded hotel projection');
echo "$passed passed; 0 failed\n";
