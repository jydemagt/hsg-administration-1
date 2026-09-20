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
$recent=$pdo->query("SELECT m.change_qty delta,m.created_at,m.movement_type,p.name product_name,l.name location_name FROM lager_stock_movements m JOIN lager_products p ON p.id=m.product_id JOIN lager_locations l ON l.id=m.location_id ORDER BY m.created_at DESC LIMIT 10")->fetchAll();

$todayOrders = 0; $todayRevenue = 0.0;
if(db_table_exists($pdo, 'hsg_woocommerce_orders')) {
  $stTod = $pdo->query("SELECT COUNT(*) orders_count, COALESCE(SUM(total_amount),0) total_rev FROM hsg_woocommerce_orders WHERE DATE(date_created) = CURDATE() AND status NOT IN ('cancelled','refunded','failed')")->fetch(PDO::FETCH_ASSOC);
  $todayOrders = (int)($stTod['orders_count'] ?? 0);
  $todayRevenue = (float)($stTod['total_rev'] ?? 0.0);
}

$lastBackup=null;if(is_admin()&&db_table_exists($pdo,'hsg_backup_runs'))$lastBackup=$pdo->query("SELECT * FROM hsg_backup_runs ORDER BY created_at DESC,id DESC LIMIT 1")->fetch();
$wcStatus=null;if(is_admin()&&db_table_exists($pdo,'hsg_settings'))$wcStatus=setting_get($pdo,'wc_last_sync_status','not_configured');
$quality=is_admin()?hsg_quality_summary($pdo):null;
$available=max(0,(int)$stats['physical']-(int)$stats['reserved_qty']);
$reservedQty=(int)$stats['reserved_qty'];
$totalPhysicalPlusReserved=$available + $reservedQty;

page_header('Overblik');
?>

<div class="grid overview-primary" style="margin-bottom:16px;">
  <a class="card metric quality-card-link" href="reports.php?tab=builder&period=today">
    <strong><?=$todayOrders?> ordrer</strong>
    <span>Salgsomsaetning i dag: <?=number_format($todayRevenue, 2, ',', '.')?> kr</span>
  </a>
  <a class="card metric quality-card-link" href="products.php?status=active">
    <strong><?=intval($stats['products'])?></strong>
    <span>Aktive produkter</span>
  </a>
  <a class="card metric quality-card-link" href="status.php">
    <strong><?=$available?> stk.</strong>
    <span>Disponibelt lager</span>
  </a>
  <a class="card metric quality-card-link" href="reservations.php?status=reserved">
    <strong><?=$reservedQty?> flasker</strong>
    <span>Reserveret i alt</span>
  </a>
  <a class="card metric quality-card-link" href="system.php">
    <strong>✓ OK</strong>
    <span>Systemstatus</span>
  </a>
</div>

<?php if(is_admin()&&$quality):?>
<div class="card" id="action-required" style="border-left: 5px solid var(--amber, #b54708);">
  <div class="page-title" style="margin-bottom:10px;">
    <div>
      <h2 style="margin:0;">⚠️ Kræver handling</h2>
      <p class="muted" style="margin:4px 0 0;">Prioriterede administrative opgaver der kræver opmærksomhed nu.</p>
    </div>
    <a class="button secondary" href="quality.php">Datakvalitets-center</a>
  </div>
  <div class="overview-alerts">
    <a class="overview-alert <?=$quality['missing_data']?'warning':''?>" href="quality.php?filter=missing_data"><strong><?=$quality['missing_data']?></strong><span>Varer mangler data</span></a>
    <a class="overview-alert <?=$quality['missing_image']?'warning':''?>" href="quality.php?filter=missing_image"><strong><?=$quality['missing_image']?></strong><span>Mangler godkendt billede</span></a>
    <a class="overview-alert <?=$negative?'danger':''?>" href="products.php?filter=negative_stock"><strong><?=$negative?></strong><span>Negativt fysisk lager</span></a>
    <a class="overview-alert <?=$stats['active_reservations']?'warning':''?>" href="reservations.php?status=reserved"><strong><?=intval($stats['active_reservations'])?></strong><span>Aktive reservationer</span></a>
    <a class="overview-alert <?=(!$lastBackup||in_array($lastBackup['status'],['failed','warning'],true))?'warning':''?>" href="backup.php"><strong><?=!$lastBackup?'–':(in_array($lastBackup['status'],['success'],true)?'OK':'!')?></strong><span><?=!$lastBackup?'Backup ikke testet':'Seneste backup: '.h($lastBackup['status'])?></span></a>
    <a class="overview-alert <?=$wcStatus==='error'?'danger':''?>" href="reports.php?tab=settings"><strong><?=$wcStatus==='success'?'OK':($wcStatus==='error'?'Fejl':'Ej sat')?></strong><span>WooCommerce Sync</span></a>
  </div>
</div>
<?php endif;?>

<div class="card">
  <h2>⚡ Hurtige handlinger (Quick Actions)</h2>
  <div class="actions">
    <?php if(can('inventory.view')):?><a class="button" href="status.php">📦 Åbn lager</a><?php endif;?>
    <?php if(can('products.view')):?><a class="button secondary" href="products.php">🍾 Se produkter</a><?php endif;?>
    <?php if(is_admin() && can('products.view')):?><a class="button secondary" href="products.php#new-product">➕ Opret produkt</a><?php endif;?>
    <?php if(can('reservations.view')):?><a class="button secondary" href="reservations.php">🔖 Se reservationer</a><?php endif;?>
    <?php if(can('reservations.create')):?><a class="button secondary" href="status.php">📝 Opret reservation</a><?php endif;?>
    <?php if(can('catalog.view')):?><a class="button secondary" href="catalog.php">📖 Katalog</a><?php endif;?>
    <?php if(is_admin()):?>
      <a class="button secondary" href="import_center.php">📥 Import / Upload</a>
      <a class="button secondary" href="reports.php">📊 Rapporter</a>
      <a class="button secondary" href="reports.php?tab=settings">🛒 WooCommerce</a>
      <a class="button secondary" href="users.php">👥 Brugere</a>
      <a class="button secondary" href="system.php">⚙️ Systemstatus</a>
    <?php endif;?>
  </div>
</div>

<details class="card"><summary class="collapsible-summary">Seneste lagerbevægelser</summary><div class="table-wrap" style="margin-top:14px"><table><thead><tr><th>Dato</th><th>Produkt</th><th>Lokation</th><th>Ændring</th><th>Type</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['product_name'])?></td><td><?=h($r['location_name'])?></td><td class="<?=$r['delta']<0?'negative':'available'?>"><?=$r['delta']>0?'+':''?><?=$r['delta']?></td><td><?=h($r['movement_type'])?></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="5" class="muted">Ingen lagerbevægelser endnu.</td></tr><?php endif;?></tbody></table></div></details>
<?php page_footer();
