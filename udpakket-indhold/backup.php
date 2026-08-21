<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_module_enabled('backup');require_capability('backup.manage');
require_once __DIR__.'/core/backup.php';require_once __DIR__.'/core/onedrive.php';
date_default_timezone_set((string)setting_get($pdo,'timezone','Europe/Copenhagen'));

$storage=hsg_backup_storage_dir();
if(isset($_GET['download'])){
    $name=basename((string)$_GET['download']);
    if(!preg_match('/^HSG-(?:DataBackup|FullBackup)-[0-9\-]+\.zip$/',$name)) {http_response_code(400);exit('Ugyldigt filnavn.');}
    $file=$storage.'/'.$name;if(!is_file($file)){http_response_code(404);exit('Backupfilen findes ikke.');}
    audit_log($pdo,'backup.download','backup',null,['filename'=>$name]);
    header('Content-Type: application/zip');header('Content-Length: '.filesize($file));header('Content-Disposition: attachment; filename="'.$name.'"');readfile($file);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'');
    try{
        if($action==='save_settings'){
            foreach(['backup_enabled','backup_full_weekly','onedrive_enabled'] as $k) setting_set($pdo,$k,!empty($_POST[$k])?'1':'0');
            setting_set($pdo,'backup_full_weekday',(string)max(0,min(6,(int)($_POST['backup_full_weekday']??0))));
            setting_set($pdo,'backup_keep_data',(string)max(1,min(365,(int)($_POST['backup_keep_data']??30))));
            setting_set($pdo,'backup_keep_full',(string)max(1,min(120,(int)($_POST['backup_keep_full']??12))));
            foreach(['onedrive_tenant_id','onedrive_client_id','onedrive_user_id','onedrive_folder'] as $k) setting_set($pdo,$k,trim((string)($_POST[$k]??'')));
            if(trim((string)($_POST['onedrive_client_secret']??''))!=='') setting_set($pdo,'onedrive_client_secret',(string)$_POST['onedrive_client_secret']);
            audit_log($pdo,'backup.settings','system');flash('success','Backupindstillinger gemt.');
        }elseif($action==='new_cron_key'){
            setting_set($pdo,'backup_cron_key',bin2hex(random_bytes(24)));audit_log($pdo,'backup.cron_key.rotate','system');flash('success','Ny cron-nøgle oprettet. Husk at opdatere webhotellets URL-cron, hvis du bruger den.');
        }elseif($action==='backup_data'){
            $r=hsg_make_backup($pdo,'data','manual');hsg_backup_cleanup($pdo);flash('success','DATA-backup oprettet og valideret: '.$r['filename']);
        }elseif($action==='backup_full'){
            $r=hsg_make_backup($pdo,'full','manual');hsg_backup_cleanup($pdo);flash('success','FULL disaster-recovery backup oprettet og valideret: '.$r['filename']);
        }elseif($action==='test_onedrive'){
            $r=hsg_onedrive_test($pdo);flash('success','OneDrive-forbindelsen virker. Backupmappen er tilgængelig.');
        }elseif($action==='cleanup'){
            $d=hsg_backup_cleanup($pdo);flash('success',count($d).' ældre lokale backupfiler blev ryddet op.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('backup.php');
}

$rows=db_table_exists($pdo,'hsg_backup_runs')?$pdo->query('SELECT * FROM hsg_backup_runs ORDER BY created_at DESC,id DESC LIMIT 80')->fetchAll():[];
$cronKey=(string)setting_get($pdo,'backup_cron_key','');
$cronUrl=base_url().'/cron/backup.php?key='.$cronKey;
$cliPath=__DIR__.'/cron/backup.php';
$weekdays=['Søndag','Mandag','Tirsdag','Onsdag','Torsdag','Fredag','Lørdag'];
$zipOk=class_exists('ZipArchive');$curlOk=function_exists('curl_init');
page_header('Backup & disaster recovery');
?>
<div class="grid">
  <div class="card metric"><strong><?=$zipOk?'✓':'!'?></strong><span>ZipArchive <?=$zipOk?'klar':'mangler'?></span></div>
  <div class="card metric"><strong><?=$curlOk?'✓':'!'?></strong><span>cURL <?=$curlOk?'klar':'mangler'?></span></div>
  <div class="card metric"><strong><?=count(array_filter($rows,fn($r)=>$r['status']==='success'))?></strong><span>Seneste backupkørsler OK</span></div>
</div>

<div class="split">
<div class="card"><h2>Lav backup nu</h2><p><strong>DATA:</strong> database + uploads. Velegnet til den natlige backup.</p><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="backup_data"><button>Lav DATA-backup</button></form><hr><p><strong>FULL:</strong> hele HSG Administration + database + uploads + restore.php + README. Bruges hvis hele webhotellet går tabt.</p><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="backup_full"><button>Lav FULL-backup</button></form></div>
<div class="card"><h2>Restore-pakken</h2><p>En FULL-backup indeholder:</p><ul><li><code>site/</code> – alle systemfiler og uploads</li><li><code>database.sql</code> – alle HSG-tabeller</li><li><code>restore.php</code> – selvstændigt gendannelsesværktøj</li><li><code>README-RESTORE.txt</code> – trin-for-trin vejledning</li><li><code>manifest.json</code> – version, checksum og kontrolinformation</li></ul><p class="muted">Databasekodeord, OneDrive client secret, Groq/OpenAI API-nøgler og cron-nøgle medtages ikke som hemmeligheder i backup-pakken.</p></div>
</div>

<div class="card"><h2>Automatisk backup</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_settings">
<div class="three"><label class="check"><input type="checkbox" name="backup_enabled" value="1" <?=setting_get($pdo,'backup_enabled','1')==='1'?'checked':''?>> Natlig DATA-backup aktiv</label><label class="check"><input type="checkbox" name="backup_full_weekly" value="1" <?=setting_get($pdo,'backup_full_weekly','1')==='1'?'checked':''?>> Ugentlig FULL-backup aktiv</label><label>FULL-backup dag<select name="backup_full_weekday"><?php foreach($weekdays as $i=>$d):?><option value="<?=$i?>" <?=((int)setting_get($pdo,'backup_full_weekday','0')===$i)?'selected':''?>><?=h($d)?></option><?php endforeach;?></select></label></div>
<div class="split"><label>Behold DATA-backups<input type="number" min="1" max="365" name="backup_keep_data" value="<?=h(setting_get($pdo,'backup_keep_data','30'))?>"><small class="muted">Antal lokale ZIP-filer.</small></label><label>Behold FULL-backups<input type="number" min="1" max="120" name="backup_keep_full" value="<?=h(setting_get($pdo,'backup_keep_full','12'))?>"><small class="muted">Antal lokale ZIP-filer.</small></label></div>
<hr><h3>OneDrive (valgfrit)</h3><label class="check"><input type="checkbox" name="onedrive_enabled" value="1" <?=setting_get($pdo,'onedrive_enabled','0')==='1'?'checked':''?>> Upload nye backups til OneDrive</label><div class="two"><label>Microsoft tenant ID<input name="onedrive_tenant_id" value="<?=h(setting_get($pdo,'onedrive_tenant_id',''))?>"></label><label>Application / client ID<input name="onedrive_client_id" value="<?=h(setting_get($pdo,'onedrive_client_id',''))?>"></label><label>OneDrive bruger (UPN)<input name="onedrive_user_id" placeholder="backup@firma.dk" value="<?=h(setting_get($pdo,'onedrive_user_id',''))?>"></label><label>OneDrive mappe<input name="onedrive_folder" value="<?=h(setting_get($pdo,'onedrive_folder','HSG Administration/Backups'))?>"></label></div><label>Client secret<input type="password" name="onedrive_client_secret" placeholder="Lad feltet være tomt for at beholde eksisterende secret"><small class="muted">Kræver Microsoft Graph application-permission til filer og admin consent. Secret udelades fra backup.</small></label>
<button>Gem backupindstillinger</button></form><p><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="test_onedrive"><button class="secondary">Test OneDrive</button></form></p></div>

<div class="card"><h2>Webhotel cron</h2><p>Systemet kan ikke selv starte PHP, når ingen besøger siden. Sæt derfor webhotellets cron til at kalde backupscriptet én gang hver nat, fx kl. 02:00.</p><label>Anbefalet CLI-kommando<input readonly value="php <?=h($cliPath)?>" onclick="this.select()"></label><p class="muted">Hvis webhotellet kun understøtter URL-kald:</p><label>Beskyttet cron-URL<input readonly value="<?=h($cronUrl)?>" onclick="this.select()"></label><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="new_cron_key"><button class="secondary">Generér ny cron-nøgle</button></form></div>

<div class="card"><h2>Backuphistorik</h2><div class="table-wrap"><table><thead><tr><th>Dato</th><th>Type</th><th>Status</th><th>Destination</th><th>Fil</th><th>Størrelse</th><th>Kontrol</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=h($r['created_at'])?></td><td><strong><?=h(strtoupper($r['backup_type']))?></strong></td><td><span class="badge <?=$r['status']==='success'?'green':''?>"><?=h($r['status'])?></span></td><td><?=h($r['destination'])?></td><td><?php if($r['filename'] && is_file($storage.'/'.$r['filename'])):?><a href="backup.php?download=<?=rawurlencode($r['filename'])?>"><?=h($r['filename'])?></a><?php else:?><?=h($r['filename']??'-')?><?php endif;?></td><td><?=$r['file_size']?h(number_format(((int)$r['file_size'])/1024/1024,1,',','.').' MB'):'-'?></td><td><small><?=h($r['message']??'')?></small><?php if($r['checksum_sha256']):?><br><code><?=h(substr($r['checksum_sha256'],0,16))?>…</code><?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="7" class="muted">Der er endnu ikke lavet en backup.</td></tr><?php endif;?></tbody></table></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cleanup"><button class="secondary">Ryd op efter retention-regler</button></form></div>
<?php page_footer();
