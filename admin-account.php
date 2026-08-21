<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_admin();
$id=current_admin_id();
$st=$pdo->prepare('SELECT * FROM lager_admins WHERE id=?');$st->execute([$id]);$admin=$st->fetch();if(!$admin){http_response_code(404);exit('Admin-konto findes ikke.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $username=trim((string)($_POST['username']??''));$display=trim((string)($_POST['display_name']??''));$current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
        if(!password_verify($current,(string)$admin['password_hash']))throw new RuntimeException('Nuværende adgangskode er forkert.');
        if(strlen($username)<3)throw new RuntimeException('Brugernavnet skal være mindst 3 tegn.');
        if($new!==''&&strlen($new)<12)throw new RuntimeException('Ny adgangskode skal være mindst 12 tegn.');
        if($new!==''&&!hash_equals($new,$confirm))throw new RuntimeException('De nye adgangskoder er ikke ens.');
        if($new!==''){$pdo->prepare('UPDATE lager_admins SET username=?,display_name=?,password_hash=? WHERE id=?')->execute([$username,$display?:'Administrator',password_hash($new,PASSWORD_DEFAULT),$id]);}else{$pdo->prepare('UPDATE lager_admins SET username=?,display_name=? WHERE id=?')->execute([$username,$display?:'Administrator',$id]);}
        $_SESSION['admin_name']=$display?:'Administrator';audit_log($pdo,'admin_account.update','admin',(string)$id,['username'=>$username,'password_changed'=>$new!==''?1:0]);flash('success','Admin-kontoen er opdateret.');redirect('admin-account.php');
    }catch(Throwable $e){flash('error',$e->getMessage());redirect('admin-account.php');}
}
page_header('Admin-konto');
?>
<div class="card narrow" style="margin-top:0"><h2>Sikker administrator</h2><form method="post"><?=csrf_field()?><label>Visningsnavn<input name="display_name" value="<?=h($admin['display_name'])?>" required></label><label>Brugernavn<input name="username" value="<?=h($admin['username'])?>" autocomplete="username" required></label><label>Nuværende adgangskode<input type="password" name="current_password" autocomplete="current-password" required></label><label>Ny adgangskode <span class="muted">(lad stå tom for at beholde den nuværende)</span><input type="password" name="new_password" autocomplete="new-password"></label><label>Gentag ny adgangskode<input type="password" name="confirm_password" autocomplete="new-password"></label><button>Gem admin-konto</button></form></div>
<?php page_footer();
