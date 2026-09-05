<?php
declare(strict_types=1);

function hsg_link_module_access(string $moduleId): array {
    if(!is_link_user() || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return ['view'=>false,'operate'=>false];
    $pdo=$GLOBALS['pdo']; $uid=current_link_user_id(); if(!$uid) return ['view'=>false,'operate'=>false];
    if(!function_exists('db_table_exists') || !db_table_exists($pdo,'hsg_user_module_access')){
        $defaults=['dashboard'=>[true,false],'inventory'=>[true,false],'reservations'=>[true,true],'catalog'=>[true,false]];
        $d=$defaults[$moduleId]??[false,false]; return ['view'=>$d[0],'operate'=>$d[1]];
    }
    $st=$pdo->prepare('SELECT can_view,can_operate FROM hsg_user_module_access WHERE user_id=? AND module_id=?');$st->execute([$uid,$moduleId]);$r=$st->fetch();
    if(!$r) return ['view'=>false,'operate'=>false];
    return ['view'=>(bool)$r['can_view'],'operate'=>(bool)$r['can_operate']];
}

function hsg_link_can_view_module(string $moduleId): bool { return hsg_link_module_access($moduleId)['view']; }
function hsg_link_can_operate_module(string $moduleId): bool { return hsg_link_module_access($moduleId)['operate']; }

function hsg_admin_can_view_module(string $moduleId): bool {
    if(!is_admin()) return false;
    if(is_superadmin()) return true;
    if(!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) return true;
    $pdo=$GLOBALS['pdo']; $aid=current_admin_id(); if(!$aid) return false;
    if(!function_exists('db_table_exists') || !db_table_exists($pdo,'hsg_admin_module_access')) return true;
    $st=$pdo->prepare('SELECT can_view FROM hsg_admin_module_access WHERE admin_id=? AND module_id=?');
    $st->execute([$aid,$moduleId]); $r=$st->fetch();
    return $r ? (bool)$r['can_view'] : true;
}

function hsg_capabilities(): array {
    if (is_admin()) {
        if (is_superadmin()) return ['*'];
        $caps = []; // Almindelige login-brugere har IKKE adgang til brugestyring (users.manage)
        $modules = function_exists('hsg_module_manifests') ? hsg_module_manifests() : [];
        foreach ($modules as $modId => $m) {
            if ($modId === 'access') continue; // Oprettelse og brugestyring er forbeholdt superadmin
            if (hsg_admin_can_view_module((string)$modId)) {
                $cap = (string)($m['capability'] ?? '');
                if ($cap !== '') $caps[] = $cap;
                $caps[] = $modId . '.view';
                $caps[] = $modId . '.operate';
            }
        }
        $caps[] = 'dashboard.view';
        $caps[] = 'inventory.view';
        $caps[] = 'reservations.view';
        $caps[] = 'reservations.create';
        $caps[] = 'catalog.view';
        return array_values(array_unique($caps));
    }
    if (!is_link_user()) return [];
    $caps=[];
    if(hsg_link_can_view_module('dashboard')) $caps[]='dashboard.view';
    if(hsg_link_can_view_module('inventory')) $caps[]='inventory.view';
    if(hsg_link_can_view_module('reservations')) $caps[]='reservations.view';
    if(hsg_link_can_operate_module('reservations')) { $caps[]='reservations.create'; $caps[]='reservations.cancel_own'; }
    if(hsg_link_can_view_module('catalog')) $caps[]='catalog.view';
    return $caps;
}

function can(string $capability): bool {
    $caps = hsg_capabilities();
    return in_array('*', $caps, true) || in_array($capability, $caps, true);
}

function require_capability(string $capability): void {
    if (!can($capability)) {
        http_response_code(403);
        exit('Du har ikke rettighed til denne funktion.');
    }
}
