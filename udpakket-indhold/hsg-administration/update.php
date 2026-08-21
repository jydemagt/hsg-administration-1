<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/core/updater.php';
hsg_update_cleanup_old_staging();

function hsg_staged_update_from_session(): ?array {
    $s=$_SESSION['hsg_staged_update']??null;
    if(!is_array($s) || empty($s['path']) || empty($s['sha256'])) return null;
    if(!is_file((string)$s['path'])) { unset($_SESSION['hsg_staged_update']); return null; }
    return $s;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'inspect');
    try{
        if($action==='inspect'){
            $old=hsg_staged_update_from_session(); if($old) hsg_update_cleanup_staged((string)$old['path']);
            $info=hsg_update_stage_uploaded_file($_FILES['package']??[]);
            $_SESSION['hsg_staged_update']=[
                'path'=>$info['path'],'sha256'=>$info['package_sha256'],'original_name'=>$info['original_name'],
                'version'=>$info['version'],'current_version'=>$info['current_version'],'release_notes'=>$info['release_notes'],
                'file_count'=>$info['file_count'],'package_size'=>$info['package_size'],'min_php'=>$info['min_php'],
            ];
            flash('success','Pakken er kontrolleret. Gennemgå oplysningerne og vælg Installér opgradering.');
        } elseif($action==='cancel'){
            $staged=hsg_staged_update_from_session();if($staged)hsg_update_cleanup_staged((string)$staged['path']);unset($_SESSION['hsg_staged_update']);
            flash('success','Den kontrollerede opgraderingspakke er fjernet.');
        } elseif($action==='install'){
            $staged=hsg_staged_update_from_session();
            if(!$staged) throw new RuntimeException('Ingen kontrolleret opgraderingspakke er klar.');
            $result=hsg_install_staged_update($pdo,(string)$staged['path'],(string)$staged['sha256'],(string)$staged['original_name']);
            unset($_SESSION['hsg_staged_update']);
            // New code must be loaded on a fresh request.
            $_SESSION['flash'][]=['success',$result['message'].' Pre-update backup: '.$result['backup']];
            header('Location: update.php?updated=1');exit;
        }
    }catch(Throwable $e){
        flash('error',$e->getMessage());
    }
    redirect('update.php');
}

$staged=hsg_staged_update_from_session();
$history=db_table_exists($pdo,'hsg_update_runs')?$pdo->query('SELECT * FROM hsg_update_runs ORDER BY created_at DESC,id DESC LIMIT 30')->fetchAll():[];
$uploadLimit=ini_get('upload_max_filesize')?:'?';$postLimit=ini_get('post_max_size')?:'?';
page_header('Opgradering');
?>
<div class="grid">
  <div class="card metric"><strong><?=h(app_version())?></strong><span>Installeret version</span></div>
  <div class="card metric"><strong><?=h(PHP_VERSION)?></strong><span>PHP-version</span></div>
  <div class="card metric"><strong><?=class_exists('ZipArchive')?'OK':'Mangler'?></strong><span>ZIP-understøttelse</span></div>
</div>

<div class="card">
  <h2>Upload ny HSG-version</h2>
  <p class="muted">Upload en ZIP-pakke fra HSG Administration. Systemet kontrollerer først produkt, versionsnummer, filstier, PHP-krav og integritet. Intet installeres ved selve kontrollen.</p>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="inspect">
    <label>Opgraderingspakke (.zip)<input type="file" name="package" accept=".zip,application/zip" required></label>
    <p class="muted">Webhotel: upload_max_filesize <?=h($uploadLimit)?> · post_max_size <?=h($postLimit)?>. HSG accepterer maksimalt 100 MB pr. kodepakke.</p>
    <button>Kontrollér pakke</button>
  </form>
</div>

<?php if($staged): ?>
<div class="card">
  <h2>Klar til installation</h2>
  <div class="table-wrap"><table><tbody>
    <tr><th>Fil</th><td><?=h($staged['original_name'])?></td></tr>
    <tr><th>Installeret version</th><td><?=h($staged['current_version'])?></td></tr>
    <tr><th>Ny version</th><td><strong><?=h($staged['version'])?></strong></td></tr>
    <tr><th>Filer i pakken</th><td><?=h((string)$staged['file_count'])?></td></tr>
    <tr><th>Pakkestørrelse</th><td><?=h(number_format(((int)$staged['package_size'])/1024/1024,2,',','.'))?> MB</td></tr>
    <tr><th>Minimum PHP</th><td><?=h($staged['min_php'])?></td></tr>
    <tr><th>SHA-256</th><td><code><?=h($staged['sha256'])?></code></td></tr>
  </tbody></table></div>
  <?php if(trim((string)$staged['release_notes'])!==''): ?><h3>Ændringer</h3><p><?=nl2br(h($staged['release_notes']))?></p><?php endif; ?>
  <div class="readonly-note"><strong>Før installation:</strong> HSG laver automatisk en FULL-backup. <code>config.php</code>, uploads, eksisterende backups og mutable data overskrives ikke. Under selve opgraderingen sættes sitet kortvarigt i vedligeholdelsestilstand.</div>
  <div class="split-actions">
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="install"><button>Installér opgradering</button></form>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cancel"><button class="secondary">Annuller</button></form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Sådan virker opgradering</h2>
  <ol>
    <li>ZIP-pakken uploades til en privat midlertidig mappe.</li>
    <li>Pakken valideres og alle filhashes kontrolleres.</li>
    <li>Du ser version og release notes, før du installerer.</li>
    <li>Ved installation oprettes automatisk en FULL disaster-recovery-backup.</li>
    <li>Sitet går kort i vedligeholdelsestilstand, kodefiler opdateres, og databaseændringer køres.</li>
    <li>Hvis filopdateringen fejler, forsøger systemet automatisk at lægge de tidligere kodefiler tilbage. FULL-backuppen bevares altid.</li>
  </ol>
  <p class="muted"><strong>Vigtigt:</strong> upload kun opgraderingspakker, du har fået som HSG Administration-pakker. Filhash-kontrollen beskytter mod beskadigede pakker, men kan ikke gøre en pakke fra en ukendt afsender troværdig.</p>
</div>

<div class="card">
  <h2>Opgraderingshistorik</h2>
  <?php if(!$history): ?><p class="muted">Ingen opgraderinger er registreret endnu.</p><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Dato</th><th>Fra</th><th>Til</th><th>Status</th><th>Filer</th><th>Pre-backup</th><th>Besked</th></tr></thead><tbody>
  <?php foreach($history as $r): ?>
    <tr><td><?=h($r['created_at'])?></td><td><?=h($r['version_from'])?></td><td><?=h($r['version_to'])?></td><td><?=h($r['status'])?></td><td><?=h((string)$r['files_changed'])?></td><td><?=h($r['backup_filename']??'-')?></td><td><?=h($r['message']??'')?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
