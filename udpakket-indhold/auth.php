<?php
declare(strict_types=1);
require_once __DIR__.'/session.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/core/permissions.php';
require_once __DIR__.'/core/hooks.php';
require_once __DIR__.'/core/modules.php';
require_once __DIR__.'/core/audit.php';
require_once __DIR__.'/core/settings.php';

// Kort vedligeholdelsestilstand under en selvopgradering. En forældet lås
// udløber automatisk efter 30 minutter, så et afbrudt PHP-kald ikke låser sitet permanent.
$maintenanceFile=__DIR__.'/.hsg-maintenance';
if(is_file($maintenanceFile) && basename($_SERVER['SCRIPT_NAME']??'')!=='update.php'){
    $age=time()-(int)@filemtime($maintenanceFile);
    if($age>1800){ @unlink($maintenanceFile); }
    else {
        http_response_code(503);
        header('Retry-After: 60');
        echo '<!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>HSG opgraderes</title></head><body><main class="public"><div class="card narrow"><h1>HSG Administration opgraderes</h1><p>Systemet er kortvarigt i vedligeholdelsestilstand. Prøv siden igen om et øjeblik.</p></div></main></body></html>';
        exit;
    }
}

hsg_load_module_hooks();

// Admin-sessioner udløber efter 8 timers inaktivitet.
if (($_SESSION['auth_mode'] ?? '') === 'admin') {
    $last = (int)($_SESSION['admin_last_activity'] ?? 0);
    if ($last > 0 && time() - $last > 8 * 3600) {
        unset($_SESSION['auth_mode'], $_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_last_activity']);
        flash('warning', 'Admin-sessionen er udløbet. Log ind igen.');
        redirect('admin-login.php');
    }
    $_SESSION['admin_last_activity'] = time();
}

// Personlige links giver altid link-adgang, aldrig administratorrettigheder.
if (isset($_GET['k']) && is_string($_GET['k'])) {
    $raw = trim($_GET['k']);
    if (strlen($raw) >= 32) {
        $hash = hash('sha256', $raw);
        $st = $pdo->prepare('SELECT id,name,role FROM lager_users WHERE token_hash=? AND active=1 LIMIT 1');
        $st->execute([$hash]);
        if ($u = $st->fetch()) {
            session_regenerate_id(true);
            $_SESSION['auth_mode'] = 'link';
            $_SESSION['user_id'] = (int)$u['id'];
            $_SESSION['user_name'] = (string)$u['name'];
            $_SESSION['link_legacy_role'] = (string)$u['role']; // Kun til sikker opgradering fra ældre v1.0.
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_last_activity']);
            $pdo->prepare('UPDATE lager_users SET last_used_at=NOW() WHERE id=?')->execute([$u['id']]);

            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM lager_admins WHERE active=1')->fetchColumn();
            if ($adminCount === 0 && $u['role'] === 'admin') {
                redirect('admin-setup.php');
            }
            $visible=hsg_visible_modules();
            $first=$visible ? reset($visible) : null;
            if(is_array($first) && !empty($first['href'])) redirect((string)$first['href']);
            http_response_code(403);exit('Dette adgangslink har ingen aktive moduler. Kontakt administrator.');
        }
    }
    http_response_code(403);
    exit('Adgangslinket er ugyldigt eller deaktiveret.');
}

if (!is_authenticated()) {
    http_response_code(401);
    echo '<!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>HSG Administration</title></head><body><main class="public"><div class="card narrow"><h1>HSG Administration</h1><p>Åbn dit personlige lagerlink for read-only adgang og reservationer.</p><p><a class="button secondary" href="admin-login.php">Administrator-login</a></p></div></main></body></html>';
    exit;
}

verify_csrf();
