<?php
/** Authenticated public support inbox for Admin, Manager, and Receptionist. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), ['public-support-inbox','public-support-reply','public-support-close'], true)) { return; }
$routeHandled = true;
requireRoles($loggedInStaff,['admin','manager','receptionist']);

switch ($action) {
    case 'public-support-inbox':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break;
        }
        try {
            $data=tamasyaPublicSupportInbox($pdo,isset($_GET['conversationId'])?(string)$_GET['conversationId']:null);
            echo json_encode(['success'=>true]+$data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Kotak masuk bantuan gagal dibaca',$e)]);
        }
        break;

    case 'public-support-reply':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break;
        }
        try {
            $conversationId=trim((string)($input['conversationId']??''));
            $message=trim((string)($input['message']??$input['text']??''));
            if ($message==='' || tamasyaStringLength($message)>2000) throw new InvalidArgumentException('Balasan harus berisi 1 sampai 2.000 karakter.');
            $result=tamasyaPublicSupportReply($pdo,$conversationId,$message,$loggedInStaff,'app',null);
            echo json_encode(['success'=>true,'message'=>tamasyaPublicSupportMessagePublicRow($result['message'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,400);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Balasan bantuan gagal dikirim',$e)]);
        }
        break;

    case 'public-support-close':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break;
        }
        try {
            $conversationId=trim((string)($input['conversationId']??''));
            tamasyaPublicSupportClose($pdo,$conversationId,$loggedInStaff);
            echo json_encode(['success'=>true,'status'=>'closed']);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,400);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Percakapan bantuan gagal ditutup',$e)]);
        }
        break;
}
