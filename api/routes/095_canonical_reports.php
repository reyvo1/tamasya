<?php
/** TAMASYA Canonical Report Engine R3 routes. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), ['canonical-report','canonical-report-types','canonical-report-email'], true)) { return; }
$routeHandled=true;

switch((string)$action){
    case 'canonical-report-types':
        requireRoles($loggedInStaff,['admin','manager','finance']);
        requireDesktopTabAccess($loggedInStaff,'report',['admin','manager','finance']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        echo tamasyaJsonEncode(['success'=>true,'types'=>tamasyaCanonicalReportTypes(),'formats'=>['pdf','xlsx','csv','json'],'engineVersion'=>TAMASYA_CANONICAL_REPORT_VERSION]);
        break;

    case 'canonical-report':
        requireRoles($loggedInStaff,['admin','manager','finance']);
        requireDesktopTabAccess($loggedInStaff,'report',['admin','manager','finance']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            $type=trim((string)($_GET['type']??'financial_summary'));
            $from=trim((string)($_GET['from']??date('Y-m-01')));
            $to=trim((string)($_GET['to']??date('Y-m-d')));
            $format=strtolower(trim((string)($_GET['format']??'json')));
            $snapshot=tamasyaCanonicalReportSnapshot($pdo,$loggedInStaff,$type,$from,$to);
            $render=tamasyaCanonicalReportRender($snapshot,$format);
            header('Content-Type: '.$render['mime']);
            header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$render['filename']).'"');
            header('X-Tamasya-Report-ID: '.$snapshot['meta']['reportId']);
            header('X-Tamasya-Report-SHA256: '.$snapshot['meta']['checksumSha256']);
            header('X-Tamasya-Report-Integrity: '.$snapshot['meta']['integrityStatus']);
            echo $render['body'];
        }catch(Throwable $e){
            tamasyaApplyExceptionHttpStatus($e,$e instanceof InvalidArgumentException?422:500);
            header('Content-Type: application/json; charset=UTF-8');
            echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Gagal membuat laporan resmi',$e)]);
        }
        break;

    case 'canonical-report-email':
        requireRoles($loggedInStaff,['admin','manager','finance']);
        requireDesktopTabAccess($loggedInStaff,'report',['admin','manager','finance']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        try{
            if(!tamasyaExternalSideEffectsAllowed())throw new RuntimeException('Pengiriman email laporan hanya boleh dilakukan Primary aktif dengan leadership lease valid.');
            $email=strtolower(trim((string)($input['email']??'')));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Alamat email penerima tidak valid.');
            $type=trim((string)($input['type']??'financial_summary'));
            $from=trim((string)($input['from']??date('Y-m-01')));
            $to=trim((string)($input['to']??date('Y-m-d')));
            $formats=is_array($input['formats']??null)?$input['formats']:['pdf','xlsx'];
            $formats=array_values(array_unique(array_intersect(array_map('strtolower',$formats),['pdf','xlsx','csv'])));
            if(!$formats)$formats=['pdf','xlsx'];
            $snapshot=tamasyaCanonicalReportSnapshot($pdo,$loggedInStaff,$type,$from,$to);
            $attachments=[];foreach($formats as $format){$r=tamasyaCanonicalReportRender($snapshot,$format);$attachments[]=['filename'=>$r['filename'],'mime'=>$r['mime'],'content'=>$r['body']];}
            $body=tamasyaCanonicalReportEmailBody($snapshot);
            $subject=$snapshot['meta']['propertyName'].' - '.$snapshot['meta']['reportLabel'].' - '.$from.' s/d '.$to;
            $confStmt=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1");$conf=$confStmt?($confStmt->fetch(PDO::FETCH_ASSOC)?:[]):[];
            $sent=false;$method='';
            if(trim((string)($conf['smtp_host']??''))!==''){$sent=sendSmtpMail($email,$subject,$body,$conf,$attachments);if($sent)$method='SMTP';}
            if(!$sent){$sent=tamasyaPhpMailWithAttachments($email,$subject,$body,$attachments,$conf);if($sent)$method='PHP mail()';}
            $masked=function_exists('tamasyaMaskEmailAddress')?tamasyaMaskEmailAddress($email):preg_replace('/(^.).*(@.*$)/','$1***$2',$email);
            $eventId='report_email_'.substr(hash('sha256',(string)($GLOBALS['tamasya_request_operation_id']??'').$snapshot['meta']['reportId']),0,32);
            if($sent){
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengirim laporan resmi canonical','canonical_report_email',$eventId,null,['reportId'=>$snapshot['meta']['reportId'],'checksum'=>$snapshot['meta']['checksumSha256'],'type'=>$type,'period'=>[$from,$to],'recipientHash'=>hash('sha256',$email),'formats'=>$formats,'method'=>$method,'integrity'=>$snapshot['meta']['integrityStatus']],'web');
                echo tamasyaJsonEncode(['success'=>true,'message'=>'Laporan resmi berhasil dikirim ke '.$masked.'.','reportId'=>$snapshot['meta']['reportId'],'checksumSha256'=>$snapshot['meta']['checksumSha256'],'integrityStatus'=>$snapshot['meta']['integrityStatus'],'formats'=>$formats,'method'=>$method]);
            }else{
                http_response_code(502);echo tamasyaJsonEncode(['success'=>false,'error'=>'Email tidak berhasil dikirim. Periksa konfigurasi SMTP/mail hosting.','reportId'=>$snapshot['meta']['reportId']]);
            }
        }catch(Throwable $e){
            tamasyaApplyExceptionHttpStatus($e,$e instanceof InvalidArgumentException?422:500);
            echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Gagal mengirim laporan resmi',$e)]);
        }
        break;
}
