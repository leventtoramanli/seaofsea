<?php
// path’leri kendi projenle eşleştir:
require_once __DIR__ . '/v1/core/DB.php';
require_once __DIR__ . '/v1/core/Crud.php';
require_once __DIR__ . '/v1/core/PermissionService.php';
require_once __DIR__ . '/v1/core/log.php';

function row1($label, $bool) {
    echo str_pad($label, 45, '.') . ' ' . ($bool ? "TRUE" : "FALSE") . '<br>' . PHP_EOL;
}

$founderEmail = 'leventtoramanli@gmail.com';
$memberEmail  = 'te@example.com';
$companyName  = 'Miray Gemicilik ve Personel LTD';

$crud = new Crud(null, false);

// Resolve IDs by email/name (no hardcoded IDs)
$f = $crud->read('users', ['email'=>$founderEmail], ['id','is_verified'], false);
$m = $crud->read('users', ['email'=>$memberEmail ], ['id','is_verified'], false);
$c = $crud->read('companies', ['name'=>$companyName, 'created_by'=>$f['id']], ['id'], false);

if (!$f || !$m || !$c) {
    echo "Fixture eksik. Önce seed SQL'i çalıştırın.\n";
    exit(1);
}

$founderId = (int)$f['id'];
$memberId  = (int)$m['id'];
$companyId = (int)$c['id'];

row1('Founder fallback (company.members.view)', PermissionService::hasPermission($founderId,'company.members.view',$companyId));
row1('Member (editor) lacks company.members.view', PermissionService::hasPermission($memberId,'company.members.view',$companyId));

// Grant → TRUE
PermissionService::assignPermission($memberId,'company.members.view',$companyId,$founderId,'smoke grant');
row1('Member after GRANT', PermissionService::hasPermission($memberId,'company.members.view',$companyId));

// Revoke → FALSE
PermissionService::revokePermission($memberId,'company.members.view',$companyId,$founderId,'smoke revoke');
row1('Member after REVOKE', PermissionService::hasPermission($memberId,'company.members.view',$companyId));

// Public permission (scope=global, is_public=1)
row1('Public profile.view (founder)', PermissionService::hasPermission($founderId,'profile.view',null));
row1('Public profile.view (member@company)', PermissionService::hasPermission($memberId,'profile.view',$companyId));

// Global admin test (opsiyonel):
// $crud->update('users', ['role_id'=>$crud->read('roles',['scope'=>'global','name'=>'admin'],['id'],false)['id']], ['id'=>$founderId]);
// row1('admin.access (founder as global admin)', PermissionService::hasPermission($founderId,'admin.access',null));
