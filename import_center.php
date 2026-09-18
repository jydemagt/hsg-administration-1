<?php
declare(strict_types=1);
require __DIR__.'/auth.php';require_admin();require_capability('imports.manage');
page_header('Import / Upload');
?>
<p class="muted admin-intro">Samlet sted for filer ind og ud af HSG. Leverandørfiler ændrer ikke lager, medmindre du bruger den almindelige lagerimport.</p>
<div class="admin-hub-grid import-hub">
  <a class="admin-hub-card" href="import.php"><span class="admin-hub-icon">⇩</span><span><strong>Lager Excel / CSV</strong><small>Importér lager og produktdata. Preview/validering sker før lageret opdateres.</small></span><span class="admin-hub-arrow">›</span></a>
  <a class="admin-hub-card" href="supplier_upload.php"><span class="admin-hub-icon">⇧</span><span><strong>Kims uploadfil</strong><small>Find priser, fadnummer, fadtype, ABV m.m. i forskellige Excel-filer og foreslå ændringer.</small></span><span class="admin-hub-arrow">›</span></a>
  <a class="admin-hub-card" href="export.php"><span class="admin-hub-icon">⇲</span><span><strong>Download Excel-arbejdsfil</strong><small>Eksportér produkter, priser og alle aktuelle lagerlokationer til en fil, der kan arbejdes videre i.</small></span><span class="admin-hub-arrow">›</span></a>
</div>
<?php page_footer();
