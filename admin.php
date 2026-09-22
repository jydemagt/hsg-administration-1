<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_admin();

$activeLinks=(int)$pdo->query('SELECT COUNT(*) FROM lager_users WHERE active=1')->fetchColumn();
$pendingImages=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE image_path IS NOT NULL AND image_path<>'' AND COALESCE(image_approval_status,'pending')<>'approved'")->fetchColumn();
$negative=(int)$pdo->query("SELECT COUNT(*) FROM lager_products p WHERE (SELECT COALESCE(SUM(s.quantity),0) FROM lager_stock s WHERE s.product_id=p.id)<0")->fetchColumn();

// Diagnostics checks
try {
  $pdo->query('SELECT 1');
  $dbOk = true;
} catch (Throwable $e) {
  $dbOk = false;
}
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$storageDir = __DIR__ . '/storage/tmp';
$storageWritable = is_dir($storageDir) && is_writable($storageDir);
$extZip = extension_loaded('zip');
$extGd = extension_loaded('gd');
$extPdo = extension_loaded('pdo');

$wcConfigured = trim((string)setting_get($pdo, 'woocommerce_shop_url', '')) !== '';
$wcLastStatus = setting_get($pdo, 'wc_last_sync_status', 'not_configured');

page_header('Administration & Systemstatus');

$groups=[
 'Daglig drift'=>[
   ['Reservationer','Se og administrér reservationer.','reservations.php','▣'],
   ['Lagerændringer','Tilføj, fjern og flyt fysisk lager.','stock.php','⇄'],
   ['Lokationer','Hovedlager, Gert Lager og øvrige lagersteder.','locations.php','⌖'],
 ],
 'Produkter & katalog'=>[
   ['Brands','Brandbeskrivelser, logoer og kataloggrupper.','brands.php','B'],
   ['Billedtjek',$pendingImages.' billeder afventer kontrol/godkendelse.','image_check.php','▧'],
   ['Datakvalitet','Manglende data, undtagelser og produktgodkendelse.','quality.php','✓'],
 ],
 'Adgang & sikkerhed'=>[
   ['Brugere & aktive links',$activeLinks.' aktive personlige adgangslinks.','users.php','⚿'],
   ['Admin-konto','Skift admin-navn og adgangskode.','admin-account.php','♙'],
   ['Backup','DATA/FULL-backup, restore og OneDrive.','backup.php','⬇'],
   ['Opgradering','Installér nye HSG-versioner direkte på siden.','update.php','⬆'],
 ],
 'System'=>[
   ['Systemindstillinger','Moduler, audit-log og teknisk status.','system.php','⚙'],
   ['Produkter med negativt lager',$negative.' produkter kan kræve oprydning.','products.php?status=all&filter=negative_stock','!'],
 ],
];
?>

<div class="card" style="margin-bottom:20px;">
  <h2>🖥️ Systemdiagnostik & Status</h2>
  <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top:12px;">
    <div class="card metric" style="padding:10px;">
      <span class="badge <?=$dbOk?'green':'red'?>"><?=$dbOk?'✓ Forbundet':'🔴 Fejl'?></span>
      <strong>Database</strong>
      <small class="muted">MySQL / MariaDB PDO</small>
    </div>
    <div class="card metric" style="padding:10px;">
      <span class="badge <?=$phpOk?'green':'amber'?>"><?=$phpOk?'✓ OK':'⚠ ' . PHP_VERSION?></span>
      <strong>PHP <?=PHP_VERSION?></strong>
      <small class="muted">Krav: PHP 8.1+</small>
    </div>
    <div class="card metric" style="padding:10px;">
      <span class="badge <?=$storageWritable?'green':'red'?>"><?=$storageWritable?'✓ Skrivbar':'🔴 Låst'?></span>
      <strong>Lagermappe</strong>
      <small class="muted">storage/tmp/</small>
    </div>
    <div class="card metric" style="padding:10px;">
      <span class="badge <?=($extZip&&$extGd&&$extPdo)?'green':'red'?>"><?=($extZip&&$extGd&&$extPdo)?'✓ Alle aktiveret':'🔴 Mangler moduler'?></span>
      <strong>PHP Udvidelser</strong>
      <small class="muted">Zip, GD, PDO</small>
    </div>
    <div class="card metric" style="padding:10px;">
      <span class="badge <?=$wcConfigured?'green':'blue'?>"><?=$wcConfigured?($wcLastStatus==='error'?'⚠ Fejl':'✓ Klar'):'Ej oprettet'?></span>
      <strong>WooCommerce API</strong>
      <small class="muted"><?=$wcConfigured?'Webshop opsat':'Konfigurér i rapporter'?></small>
    </div>
  </div>
</div>

<p class="muted admin-intro">De daglige funktioner ligger i hovedmenuen. Her er de funktioner, du normalt kun bruger ved opsætning, kontrol eller vedligeholdelse.</p>
<?php foreach($groups as $title=>$items):?>
<section class="admin-hub-section"><h2><?=h($title)?></h2><div class="admin-hub-grid">
<?php foreach($items as [$name,$desc,$href,$icon]):?><a class="admin-hub-card" href="<?=h($href)?>"><span class="admin-hub-icon"><?=h($icon)?></span><span><strong><?=h($name)?></strong><small><?=h($desc)?></small></span><span class="admin-hub-arrow">›</span></a><?php endforeach;?>
</div></section>
<?php endforeach;?>
<?php page_footer();
