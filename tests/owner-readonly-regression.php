<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/support/017_authorization_policy.php';
if(($argv[1]??'')==='--enforce'){
    $_SERVER['REQUEST_METHOD']=$argv[2];
    register_shutdown_function(static fn()=>fwrite(STDERR,(string)http_response_code()));
    tamasyaEnforceOwnerReadOnly(['role'=>'owner'],'transactions');echo 'allowed';exit;
}
if(($argv[1]??'')==='--suite-write'){
    $_SERVER['REQUEST_METHOD']=$argv[3]??'POST';$action=$argv[2];$loggedInStaff=['role'=>'owner'];$input=['command'=>'folio-create'];
    register_shutdown_function(static fn()=>fwrite(STDERR,(string)http_response_code()));
    $file=['growth-suite'=>'110_growth_suite.php','enterprise-suite'=>'112_enterprise_completion.php','website-cms-settings-save'=>'016_public_site_admin.php'][$action];
    require dirname(__DIR__).'/api/routes/'.$file;echo 'unexpected route continuation';exit;
}
$passed=0;
function ownerCheck(bool $ok,string $name): void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
$owner=['id'=>'owner1','role'=>'owner','permissions'=>json_encode(['desktopTabs'=>['finance'=>false,'operations'=>false],'capabilities'=>['manage_backup'=>true,'view_audit_log'=>false]])];
$p=tamasyaSessionPermissions($owner);
ownerCheck($p['readOnly']===true&&count(array_filter($p['desktopTabs']))===17,'Owner receives every read workspace even with old custom denials');
ownerCheck($p['capabilities']['manage_backup']===false&&$p['capabilities']['view_audit_log']===true,'Owner custom permissions cannot grant write capabilities');
$admin=['role'=>'admin','permissions'=>['desktopTabs'=>['finance'=>false],'capabilities'=>['manage_backup'=>true]]];
ownerCheck(tamasyaSessionPermissions($admin)===$admin['permissions'],'Other roles retain their explicit permissions');
foreach(['POST','PUT','PATCH','DELETE'] as $method){
    $process=proc_open([PHP_BINARY,__FILE__,'--enforce',$method],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$result=json_decode($body,true);
    ownerCheck($exit===0&&$status==='403'&&($result['code']??'')==='OWNER_READ_ONLY','Actual API guard exits before a business mutation: '.$method);
}
foreach(['growth-suite','enterprise-suite','website-cms-settings-save'] as $action)foreach(['POST','PUT','PATCH','DELETE'] as $method){
    $process=proc_open([PHP_BINARY,__FILE__,'--suite-write',$action,$method],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$result=json_decode($body,true);
    ownerCheck($exit===0&&$status==='403'&&($result['code']??'')==='OWNER_READ_ONLY','Direct route guard rejects Owner before any database access: '.$action.' '.$method);
}
$_SERVER['REQUEST_METHOD']='GET';
foreach([['admin'],['admin','manager','finance']] as $roles){requireRoles($owner,$roles);ownerCheck(hasDesktopTabAccess($owner,'config',$roles),'Actual role and menu guards allow Owner read of Admin workspace');}
// Evaluate the shipped projection functions against read-only arrays; no DB import/server.
function ownerFunction(string $file,string $name): string{
    $tokens=token_get_all(file_get_contents($file));$copy=false;$found=false;$body=false;$depth=0;$source='';
    for($i=0;$i<count($tokens);$i++){
        $token=$tokens[$i];$text=is_array($token)?$token[1]:$token;
        if(!$copy&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING&&$tokens[$j][1]===$name){$copy=true;$found=true;}}
        if(!$copy)continue;$source.=$text;
        if($token==='{'){++$depth;$body=true;}elseif($token==='}')--$depth;
        elseif(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))++$depth;
        if($body&&$depth===0)return $source;
    }
    throw new RuntimeException('Function not found: '.$name);
}
function getFullHotelData($pdo,...$scope){return $GLOBALS['ownerFixture'];}
function tamasyaNotificationTypeVisibleToUser($user,$type){return true;}
function tamasyaBusinessPolicyProjection(){return [];}
function tamasyaHotelScopeId(){return 'hotel_unit_owner';}
$root=dirname(__DIR__);
eval(ownerFunction($root.'/api/support/070_data_projection_scope.php','tamasyaCanSeeFinancialData'));
eval(ownerFunction($root.'/api/support/070_data_projection_scope.php','getRoleScopedHotelData'));
eval(ownerFunction($root.'/api/modules/hr_staff/020_identity_access_audit.php','projectOperationsCenterDataForUser'));
$GLOBALS['ownerFixture']=['notifications'=>[],'salarySlips'=>[['id'=>'salary-other']],'attendance'=>[['staffId'=>'other']],'activityLogs'=>[['id'=>'audit-other']],'telegramMessages'=>[['id'=>'message-other']],'rooms'=>[['number'=>'101']],'bookings'=>[['id'=>'booking1']],'transactions'=>[['id'=>'receipt1']],'inventory'=>[['id'=>'asset1']]];
$actual=getRoleScopedHotelData(null,$owner);
foreach(['salarySlips','attendance','activityLogs','telegramMessages','rooms','bookings','transactions','inventory'] as $key)ownerCheck($actual[$key]===$GLOBALS['ownerFixture'][$key],'Owner hotel projection retains complete Admin read collection: '.$key);
$data=['journalEntries'=>[['id'=>'journal']], 'journalLines'=>[['id'=>'line']], 'taxRules'=>[['id'=>'tax']], 'sessions'=>[['id'=>'session']], 'housekeepingTasks'=>[['id'=>'hk']], 'maintenanceTickets'=>[['actual_cost'=>100]], 'backupRuns'=>[['id'=>'backup']], 'lostFoundItems'=>[['found_by'=>'other']], 'operationalSettings'=>['mode'=>'standard']];
ownerCheck(projectOperationsCenterDataForUser($data,$owner)===$data,'Owner operations projection retains all read records and costs');
$sensitive=$data;$sensitive['operationalSettings']['smart_lock_bridge_token']='private-bridge-token';
ownerCheck(projectOperationsCenterDataForUser($sensitive,$owner)['operationalSettings']['smart_lock_bridge_token']==='[TERSEMBUNYI]','Owner operational visibility retains credential masking');
ownerCheck(!hasCapability($owner,'manage_tax_rules',['admin'])&&!hasCapability($owner,'perform_housekeeping',['admin']),'Expanded projection does not grant write capability');
ownerCheck($actual['currentUser']['role']==='owner'&&$actual['currentUser']['permissions']['readOnly']===true,'Hotel response keeps actual Owner identity and read-only preset');
// Execute the shipped overview with a PDO read fixture; no database/server is started.
function tamasyaGrowthFeatureStatus(){return ['enabled'=>true,'modules'=>[]];}
function tamasyaGrowthSuiteEnabled(){return true;}
function tamasyaGrowthSchemaReady($pdo){return true;}
function tamasyaGrowthModuleEnabled($module){return true;}
$overviewDb=new class extends PDO {
    public function __construct(){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        if(!str_starts_with($query,'SELECT '))throw new RuntimeException('Overview attempted a mutation.');
        return new class extends PDOStatement {
            public function fetchColumn(int $column=0): mixed{return 7;}
            public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed{return false;}
        };
    }
};
eval(ownerFunction($root.'/api/modules/growth_enterprise_locked/105_growth_suite.php','tamasyaGrowthOverview'));
$adminOverview=tamasyaGrowthOverview($overviewDb,['role'=>'admin']);$ownerOverview=tamasyaGrowthOverview($overviewDb,$owner);
ownerCheck($ownerOverview===$adminOverview,'Actual Growth overview exposes the same enabled module counts and health for Owner and Admin');
foreach(['vendors','purchaseOrders','channelMappings','paymentIntents'] as $key)ownerCheck(($ownerOverview['counts'][$key]??null)===7,'Owner overview includes '.$key.' without substituting zero');

echo "$passed passed; 0 failed\n";
