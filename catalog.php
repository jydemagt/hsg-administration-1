<?php
require __DIR__.'/auth.php';require_module_enabled('catalog');require_capability('catalog.view');require_once __DIR__.'/core/catalog_layout.php';
$price=(($_GET['price']??'retail')==='wholesale')?'wholesale':'retail';$priceMeta=hsg_catalog_price_meta($price);$field=$priceMeta['field'];$label=$priceMeta['label'];
$brandRows=$pdo->query("SELECT id,name,description,logo_path,sort_order FROM lager_brands ORDER BY sort_order,name")->fetchAll();$brandMeta=[];foreach($brandRows as $b)$brandMeta[(string)$b['name']]=$b;
$rows=$pdo->query("SELECT p.*,b.name brand_name,b.description brand_description,b.logo_path brand_logo_path,b.sort_order,COALESCE(st.physical,0)-COALESCE(rs.reserved,0) available FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id LEFT JOIN (SELECT product_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id WHERE p.status='active' AND p.show_in_catalog=1 AND COALESCE(st.physical,0)-COALESCE(rs.reserved,0)>0 ORDER BY COALESCE(b.sort_order,999),COALESCE(b.name,'Uden brand'),p.name")->fetchAll();
$families=[];foreach($rows as $p){$brand=(string)($p['brand_name']?:'Uden brand');$family=hsg_catalog_family($brand);if(!isset($families[$family]))$families[$family]=['sort'=>(int)($p['sort_order']??999),'sections'=>[]];$families[$family]['sections'][$brand][]=$p;}uasort($families,static fn($a,$b)=>$a['sort']<=>$b['sort']);
page_header('Produktkatalog');
?>
<div class="card"><form method="get" class="catalog-options"><label>Pristype<select name="price" onchange="this.form.submit()"><option value="wholesale" <?=$price==='wholesale'?'selected':''?>>Engrospris ekskl. moms</option><option value="retail" <?=$price==='retail'?'selected':''?>>Vejl. pris inkl. moms</option></select></label><a class="button" href="catalog_pdf.php?price=<?=h($price)?>">Download PDF i kataloglayout</a><?php if(is_admin()):?><a class="button secondary" href="products.php">Rediger produkter</a><a class="button secondary" href="brands.php">Rediger brands</a><?php endif;?></form></div>

<div class="catalog-document-preview">
  <section class="catalog-cover-preview">
    <img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky">
    <h1>Whisky Katalog</h1><p>Fra</p><h2>HSG Whisky Aps</h2><small>Opdateret <?=h(date('d. m. Y'))?></small>
  </section>

<?php foreach($families as $family=>$familyData):
  $meta=$brandMeta[$family]??null;$desc=(string)($meta['description']??'');$logo=hsg_catalog_logo_url($family,(string)($meta['logo_path']??''));
  if($desc==='')foreach($familyData['sections'] as $section=>$ps){if(!empty($brandMeta[$section]['description'])){$desc=(string)$brandMeta[$section]['description'];break;}}
?>
  <section class="catalog-brand-page">
    <div class="catalog-doc-header"><h2><?=h(hsg_catalog_family_display($family))?></h2><img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky"></div>
    <?php if($logo):?><img class="catalog-brand-main-logo" src="<?=h($logo)?>" alt="<?=h($family)?>"><?php endif;?>
    <?php if($desc!==''):?><p class="catalog-brand-description"><?=nl2br(h($desc))?></p><?php endif;?>
  </section>

  <?php foreach($familyData['sections'] as $section=>$products): foreach(array_chunk($products,2) as $pair):?>
  <section class="catalog-product-page">
    <div class="catalog-doc-header"><h2><?=h($section)?></h2><img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky"></div>
    <?php foreach($pair as $i=>$p): $dataRows=hsg_catalog_product_rows($p,$field,$label); ?>
      <?php if($i===1):?><hr><?php endif;?>
      <article class="catalog-doc-product <?=$i===1?'reverse':''?>">
        <div class="catalog-doc-info">
          <h3><?=h(hsg_catalog_product_title($p))?></h3>
          <table><?php foreach($dataRows as [$k,$v]):?><tr><th><?=h($k)?></th><td><?=h($v)?></td></tr><?php endforeach;?></table>
        </div>
        <div class="catalog-doc-image-wrap">
          <?php if(!empty($p['is_new'])):?><img class="catalog-new-sticker" src="<?=h(hsg_catalog_seed_asset_url('nyhed.jpg'))?>" alt="Nyhed"><?php endif;?>
          <?php $previewImage=!empty($p['image_path'])?$p['image_path']:null;$catalogImage=$previewImage?hsg_catalog_image_url($previewImage):null; ?>
          <img class="catalog-doc-bottle" src="<?=h($catalogImage?:product_image_url($previewImage))?>" alt="<?=h(hsg_catalog_product_title($p))?>">
          <?php if($previewImage && ($p['image_approval_status']??'')!=='approved'):?><span class="catalog-preview-pending">Afventer billedgodkendelse</span><?php endif;?>
        </div>
      </article>
    <?php endforeach;?>
  </section>
  <?php endforeach; endforeach;?>
<?php endforeach;?>
</div>
<?php if(!$rows):?><div class="card"><p>Ingen produkter med disponibelt lager er tilgængelige i kataloget.</p></div><?php endif;?>
<?php page_footer();
