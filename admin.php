<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_admin();
$activeLinks=(int)$pdo->query('SELECT COUNT(*) FROM lager_users WHERE active=1')->fetchColumn();
$pendingImages=(int)$pdo->query("SELECT COUNT(*) FROM lager_products WHERE image_path IS NOT NULL AND image_path<>'' AND COALESCE(image_approval_status,'pending')<>'approved'")->fetchColumn();
$negative=(int)$pdo->query("SELECT COUNT(*) FROM lager_products p WHERE (SELECT COALESCE(SUM(s.quantity),0) FROM lager_stock s WHERE s.product_id=p.id)<0")->fetchColumn();
page_header('Administration');
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
   ['Produkter med negativt lager',$negative.' produkter kan kræve oprydning.','products.php?negative_stock=1','!'],
 ],
];
?>
<p class="muted admin-intro">De daglige funktioner ligger i hovedmenuen. Her er de funktioner, du normalt kun bruger ved opsætning, kontrol eller vedligeholdelse.</p>
<?php foreach($groups as $title=>$items):?>
<section class="admin-hub-section"><h2><?=h($title)?></h2><div class="admin-hub-grid">
<?php foreach($items as [$name,$desc,$href,$icon]):?><a class="admin-hub-card" href="<?=h($href)?>"><span class="admin-hub-icon"><?=h($icon)?></span><span><strong><?=h($name)?></strong><small><?=h($desc)?></small></span><span class="admin-hub-arrow">›</span></a><?php endforeach;?>
</div></section>
<?php endforeach;?>
<?php page_footer();
