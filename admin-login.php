<?php
declare(strict_types=1);
require_once __DIR__.'/session.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';

if (is_admin()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // Begræns brute-force: højst 5 fejlede forsøg pr. brugernavn/IP på 15 minutter.
    $lim = $pdo->prepare("SELECT COUNT(*) FROM lager_login_attempts WHERE username=? AND ip_hash=? AND success=0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)");
    $lim->execute([$username, $ipHash]);
    if ((int)$lim->fetchColumn() >= 5) {
        $error = 'For mange loginforsøg. Prøv igen senere.';
    } else {
        $st = $pdo->prepare('SELECT id,username,display_name,password_hash FROM lager_admins WHERE username=? AND active=1 LIMIT 1');
        $st->execute([$username]);
        $admin = $st->fetch();
        $ok = $admin && password_verify($password, (string)$admin['password_hash']);
        $pdo->prepare('INSERT INTO lager_login_attempts(username,ip_hash,success) VALUES(?,?,?)')->execute([$username, $ipHash, $ok ? 1 : 0]);

        if ($ok) {
            if (password_needs_rehash((string)$admin['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE lager_admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
            }
            $pdo->prepare('UPDATE lager_admins SET last_login_at=NOW() WHERE id=?')->execute([$admin['id']]);
            $pdo->prepare('DELETE FROM lager_login_attempts WHERE username=? AND ip_hash=? AND success=0')->execute([$username, $ipHash]);
            session_regenerate_id(true);
            $_SESSION['auth_mode'] = 'admin';
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_name'] = (string)$admin['display_name'];
            $_SESSION['admin_last_activity'] = time();
            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['link_legacy_role']);
            redirect('index.php');
        }
        if (!$error) $error = 'Forkert brugernavn eller adgangskode.';
    }
}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>Admin-login · HSG Administration</title></head><body><main class="public"><div class="card narrow"><h1>Administrator-login</h1><p class="muted">Administration kræver brugernavn og adgangskode. Personlige lagerlinks giver ikke adminrettigheder.</p><?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?><?php render_flash();?><form method="post"><?=csrf_field()?><label>Brugernavn<input name="username" autocomplete="username" required autofocus></label><label>Adgangskode<input type="password" name="password" autocomplete="current-password" required></label><button>Log ind som administrator</button></form><p><a href="index.php">Tilbage til lageradgang</a></p></div></main></body></html>
