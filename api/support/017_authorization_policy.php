<?php
/**
 * TAMASYA V137 canonical authorization policy.
 *
 * FINAL11 root hardening separates role/capability/desktop-tab decisions from
 * the legacy identity/audit support. Route modules continue using compatibility
 * function names, but all authorization interpretation now lives here.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 2039: requireRoles */
function tamasyaIsOwnerRole($user): bool {
    return is_array($user) && strtolower(trim((string)($user['role'] ?? ''))) === 'owner';
}

/** Owner preset is fixed: every workspace can be read, no mutation capability. */
function tamasyaSessionPermissions(array $user) {
    $permissions=$user['permissions']??null;
    if(is_string($permissions))$permissions=json_decode($permissions,true);
    if(!tamasyaIsOwnerRole($user))return is_array($permissions)?$permissions:null;
    $tabs=['dashboard','rooms','finance','savings','report','operations','website','support','telegram','staff','config','db_config','inventory','leaves','attendance','pos','local_connect'];
    $capabilities=is_array($permissions['capabilities']??null)?$permissions['capabilities']:[];
    foreach(['approve_sensitive_actions','manage_sessions','resolve_sync_conflict','manage_tax_rules','manage_backup','reconcile_payments','correct_booking_audit','manage_room_access','perform_night_audit','perform_housekeeping','resolve_night_audit','manage_operational_settings','override_key_checkout','manage_public_website','view_audit_log','view_guest_identity'] as $key)$capabilities[$key]=str_starts_with($key,'view_');
    foreach($capabilities as $key=>&$value)$value=str_starts_with((string)$key,'view_');unset($value);
    return ['desktopTabs'=>array_fill_keys($tabs,true),'telegramNotifications'=>array_fill_keys(['bookings','finance','inventory','operations','hr','system'],false),'capabilities'=>$capabilities,'readOnly'=>true];
}

function tamasyaOwnerMutationDenied($user): bool {
    if (!tamasyaIsOwnerRole($user)) return false;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return !in_array($method, ['GET','HEAD','OPTIONS'], true);
}

function tamasyaEnforceOwnerReadOnly($user, string $action = ''): void {
    if (!tamasyaOwnerMutationDenied($user)) return;
    if ($action === 'logout') return;
    http_response_code(403);
    echo json_encode([
        'success'=>false,
        'code'=>'OWNER_READ_ONLY',
        'error'=>'Owner adalah role read-only. Perubahan data tidak diizinkan.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireRoles($user, $roles) {
    if (tamasyaIsOwnerRole($user)) {
        if (!tamasyaOwnerMutationDenied($user)) return;
    }
    if (!$user || !in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "error" => "Forbidden: Anda tidak memiliki izin untuk operasi ini."]);
        exit;
    }
}

/** Source line 2047: hasCapability */
function tamasyaPermissionOverride($permissions, string $group, string $key): ?bool {
    if($permissions===null || $permissions==='')return null;
    if(is_string($permissions))$permissions=json_decode($permissions,true);
    // Corrupt or unexpected permission shapes must deny, never grant via a role fallback.
    if(!is_array($permissions))return false;
    if(!array_key_exists($group,$permissions))return null;
    if(!is_array($permissions[$group]))return false;
    if(!array_key_exists($key,$permissions[$group]))return null;
    $value=$permissions[$group][$key];
    if(is_bool($value))return $value;
    if($value===1 || $value==='1')return true;
    if(is_string($value) && strtolower(trim($value))==='true')return true;
    return false;
}

function hasCapability($user, $capability, $fallbackRoles = []) {
    if (!$user) return false;
    if (tamasyaIsOwnerRole($user)) {
        $capability = (string)$capability;
        return str_starts_with($capability, 'view_');
    }
    $override=tamasyaPermissionOverride($user['permissions']??null,'capabilities',(string)$capability);
    if($override!==null)return $override;
    return in_array($user['role'] ?? '', $fallbackRoles, true);
}

/** Source line 2057: requireCapability */
function requireCapability($user, $capability, $fallbackRoles = []) {
    if (!hasCapability($user, $capability, $fallbackRoles)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden: izin '.$capability.' diperlukan.']);
        exit;
    }
}


/** Source line 2191: hasDesktopTabAccess */
function hasDesktopTabAccess($user, $tab, $fallbackRoles = []) {
    if (!$user) return false;
    if (tamasyaIsOwnerRole($user)) return true;
    $override=tamasyaPermissionOverride($user['permissions']??null,'desktopTabs',(string)$tab);
    if($override!==null)return $override;
    return in_array($user['role'] ?? '', $fallbackRoles, true);
}

/** Source line 2201: requireDesktopTabAccess */
function requireDesktopTabAccess($user, $tab, $fallbackRoles = []) {
    if (!hasDesktopTabAccess($user, $tab, $fallbackRoles)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden: akses menu '.$tab.' diperlukan.']);
        exit;
    }
}
