<?php
declare(strict_types=1);
require_once __DIR__.'/session.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';

$adminCount = (int)$pdo->query('SELECT COUNT(*) FROM lager_admins')->fetchColumn();
if ($adminCount > 0) redirect('admin-login.php');
if (($_SESSION['auth_mode'] ?? '') !== 'link' || ($_SESSION['link_legacy_role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    http_response_code(403); exit('Første admin-login kan kun oprettes fra det tidligere administratorlink.');
}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $display=trim((string)($_POST['display_name']??'Administrator'));
    $username=trim((string)($_POST['username']??''));
    $password=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    try{
        if(strlen($username)<3) throw new RuntimeException('Brugernavnet skal være mindst 3 tegn.');
        if(strlen($password)<12) throw new RuntimeException('Adgangskoden skal være mindst 12 tegn.');
        if(!hash_equals($password,$confirm)) throw new RuntimeException('Adgangskoderne er ikke ens.');
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO lager_admins(username,display_name,password_hash,active) VALUES(?,?,?,1)')->execute([$username,$display?:'Administrator',$hash]);
        $adminId=(int)$pdo->lastInsertId();
        $pdo->exec("UPDATE lager_users SET role='user' WHERE role='admin'");
        session_regenerate_id(true);
        $_SESSION['auth_mode']='admin';$_SESSION['admin_id']=$adminId;$_SESSION['admin_name']=$display?:'Administrator';$_SESSION['admin_last_activity']=time();
        unset($_SESSION['user_id'],$_SESSION['user_name'],$_SESSION['link_legacy_role']);
        flash('success','Sikkert administrator-login er oprettet. Det gamle direkte link er nu read-only.');
        redirect('index.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>Aktivér admin-login</title></head><body><main class="public"><div class="card narrow"><h1>Aktivér sikkert admin-login</h1><p>Du opgraderer fra en tidligere v1.0. Når dette er gjort, giver det gamle direkte link kun læseadgang og reservationsadgang.</p><?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?><form method="post"><?=csrf_field()?><label>Navn<input name="display_name" value="Administrator" required></label><label>Admin-brugernavn<input name="username" autocomplete="username" required></label><label>Ny adgangskode (mindst 12 tegn)<input type="password" name="password" autocomplete="new-password" required></label><label>Gentag adgangskode<input type="password" name="confirm_password" autocomplete="new-password" required></label><button>Opret sikkert admin-login</button></form></div></main></body></html>
