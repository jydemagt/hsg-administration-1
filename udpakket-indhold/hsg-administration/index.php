<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_capability('dashboard.view');require_once __DIR__.'/core/quality.php';
$stats=$pdo->query("SELECT
 (SELECT COUNT(*) FROM lager_products WHERE status='active') products,
 (SELECT COALESCE(SUM(quantity),0) FROM lager_stock) physical,
 (SELECT COALESCE(SUM(quantity),0) FROM lager_reservations WHERE status='reserved') reserved_qty,
 (SELECT COUNT(*) FROM lager_reservations WHERE status='reserved') active_reservations,
 (SELECT COUNT(*) FROM lager_locations WHERE active=1) locations,
 (SELECT COUNT(*) FROM lager_users WHERE active=1) active_links")->fetch();
$negative=(int)$pdo->query("SELECT COUNT(*) FROM lager_products p WHERE (SELECT COALESCE(SUM(s.quantity),0) FROM lager_stock s WHERE s.product_id=p.id)<0")->fetchColumn();
$recent=$pdo->query("SELECT m.*,p.name product_name,l.name location_name FROM lager_stock_movements m JOIN lager_products p ON p.id=m.product_id JOIN lager_locations l ON l.id=m.location_id ORDER BY m.created_at DESC LIMIT 10")->fetchAll();
$lastBackup=null;if(is_admin()&&db_table_exists($pdo,'hsg_backup_runs'))$lastBackup=$pdo->query("SELECT * FROM hsg_backup_runs ORDER BY created_at DESC,id DESC LIMIT 1")->fetch();
$quality=is_admin()?hsg_quality_summary($pdo):null;
$available=max(0,(int)$stats['physical']-(int)$stats['reserved_qty']);
page_header('Overblik');
?>
<div class="grid overview-primary">
  <a class="card metric quality-card-link" href="products.php"><strong><?=intval($stats['products'])?></strong><span>Aktive produkter</span></a>
  <a class="card metric quality-card-link" href="status.php"><strong><?=$available?></strong><span>Disponibelt lager</span></a>
  <a class="card metric quality-card-link" href="reservations.php"><strong><?=intval($stats['active_reservations'])?></strong><span>Aktive reservationer</span></a>
  <?php if(is_admin()&&$quality):?><a class="card metric quality-card-link" href="quality.php?filter=needs_action"><strong><?=$quality['needs_action']?></strong><span>Kræver handling</span></a><?php endif;?>
  <?php if(is_admin()):?><a class="card metric quality-card-link" href="users.php?status=active"><strong><?=intval($stats['active_links'])?></strong><span>Aktive links</span></a><?php endif;?>
</div>

<?php if(is_admin()&&$quality):?>
<div class="card"><div class="page-title"><div><h2 style="margin:0">Kræver opmærksomhed</h2><p class="muted" style="margin:5px 0 0">Kun de ting, der kræver en handling fra dig.</p></div><a class="button secondary" href="quality.php">Datakvalitet</a></div>
<div class="overview-alerts">
  <a class="overview-alert <?=$quality['missing_data']?'warning':''?>" href="quality.php?filter=missing_data"><strong><?=$quality['missing_data']?></strong><span>Varer mangler data</span></a>
  <a class="overview-alert <?=$quality['missing_image']?'warning':''?>" href="quality.php?filter=missing_image"><strong><?=$quality['missing_image']?></strong><span>Mangler godkendt billede</span></a>
  <a class="overview-alert <?=$negative?'danger':''?>" href="products.php?negative_stock=1"><strong><?=$negative?></strong><span>Negativt fysisk lager</span></a>
  <a class="overview-alert <?=(!$lastBackup||in_array($lastBackup['status'],['failed','warning'],true))?'warning':''?>" href="backup.php"><strong><?=!$lastBackup?'–':(in_array($lastBackup['status'],['success'],true)?'OK':'!')?></strong><span><?=!$lastBackup?'Backup ikke testet':'Seneste backup: '.h($lastBackup['status'])?></span></a>
</div></div>
<?php endif;?>

<div class="card"><h2>Hurtige handlinger</h2><div class="actions">
<?php if(can('inventory.view')):?><a class="button" href="status.php">Åbn lager</a><?php endif;?>
<?php if(can('reservations.view')):?><a class="button" href="reservations.php">Reservationer</a><?php endif;?>
<?php if(can('catalog.view')):?><a class="button secondary" href="catalog.php">Katalog</a><?php endif;?>
<?php if(is_admin()):?><a class="button secondary" href="import_center.php">Import / Upload</a><a class="button secondary" href="admin.php">Administration</a><?php endif;?>
</div></div>

<details class="card"><summary class="collapsible-summary">Seneste lagerbevægelser</summary><div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Dato</th><th>Produkt</th><th>Lokation</th><th>Ændring</th><th>Type</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['product_name'])?></td><td><?=h($r['location_name'])?></td><td class="<?=$r['delta']<0?'negative':'available'?>"><?=$r['delta']>0?'+':''?><?=$r['delta']?></td><td><?=h($r['movement_type'])?></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="5" class="muted">Ingen lagerbevægelser endnu.</td></tr><?php endif;?></tbody></table></div></details>
<?php page_footer();
