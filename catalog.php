<?php
require __DIR__.'/auth.php';require_module_enabled('catalog');require_capability('catalog.view');require_once __DIR__.'/core/catalog_layout.php';
$price=(($_GET['price']??'retail')==='wholesale')?'wholesale':'retail';
$newOnly=!empty($_GET['news']) || !empty($_GET['new_only']);
$priceMeta=hsg_catalog_price_meta($price);$field=$priceMeta['field'];$label=$priceMeta['label'];
$catalogTitle=$newOnly?'Nyhedskatalog':'Whisky Katalog';

$brandRows=$pdo->query("SELECT b.id,b.name,b.description,b.logo_path,b.sort_order,pb.name parent_name,pb.description parent_description FROM lager_brands b LEFT JOIN lager_brands pb ON pb.id=b.parent_id ORDER BY COALESCE(pb.sort_order,b.sort_order),COALESCE(pb.name,b.name),b.parent_id IS NOT NULL,b.sort_order,b.name")->fetchAll();
$brandMeta=[];
foreach($brandRows as $b){
    $desc=trim((string)($b['description']??''));
    if($desc==='' && !empty($b['parent_description'])){
        $desc=trim((string)$b['parent_description']);
    }
    $b['description']=$desc;
    $brandMeta[(string)$b['name']]=$b;
}

$whereClause="WHERE p.status='active' AND p.show_in_catalog=1 AND COALESCE(st.physical,0)-COALESCE(rs.reserved,0)>0";
if($newOnly){
    $whereClause.=" AND p.is_new=1";
}

$rows=$pdo->query("SELECT p.*,b.name brand_name,b.description brand_description,b.logo_path brand_logo_path,b.sort_order brand_sort_order,pb.name parent_brand_name,pb.description parent_brand_description,pb.sort_order parent_sort_order,COALESCE(st.physical,0)-COALESCE(rs.reserved,0) available FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN lager_brands pb ON pb.id=b.parent_id LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id LEFT JOIN (SELECT product_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id {$whereClause} ORDER BY COALESCE(pb.sort_order,b.sort_order,999),COALESCE(pb.name,b.name,'Uden brand'),b.sort_order,b.name,p.name")->fetchAll();

$families=[];
foreach($rows as $p){
    $brand=(string)($p['brand_name']?:'Uden brand');
    $parentBrand=$p['parent_brand_name']?(string)$p['parent_brand_name']:null;
    $family=hsg_catalog_family($brand,$parentBrand);
    $familySort=(int)($p['parent_sort_order']??$p['brand_sort_order']??999);
    if(!isset($families[$family]))$families[$family]=['sort'=>$familySort,'sections'=>[]];
    $families[$family]['sections'][$brand][]=$p;
}
uasort($families,static fn($a,$b)=>$a['sort']<=>$b['sort']);

function catalog_toc_page_count(array $families): int {
    if(!$families)return 1;$pages=1;$y=708;
    foreach($families as $family=>$f){
        $topGap=($y<690)?16:0;
        if($y-$topGap-18<52){$pages++;$y=748-$topGap;}$y-=$topGap+18;
        foreach($f['sections'] as $products){
            foreach($products as $_){
                if($y-14<52){$pages++;$y=748;}$y-=14;
            }
        }
    }
    return $pages;
}

$tocPages=catalog_toc_page_count($families);
$currentPage=2+$tocPages;$tocEntries=[];$pagePlan=[];
foreach($families as $family=>$f){
    $introPage=$currentPage++;$tocEntries[]=['type'=>'family','text'=>hsg_catalog_family_display($family),'page'=>$introPage];
    $familyMeta=$brandMeta[$family]??null;$familyDesc=hsg_catalog_get_family_desc($family,$f,$brandMeta);$familyLogo=(string)($familyMeta['logo_path']??'');
    $pagePlan[]=['type'=>'intro','family'=>$family,'display'=>hsg_catalog_family_display($family),'desc'=>$familyDesc,'logo'=>$familyLogo,'page'=>$introPage];
    foreach($f['sections'] as $section=>$products){
        foreach(array_chunk($products,2) as $chunk){
            $page=$currentPage++;foreach($chunk as $p){$tocEntries[]=['type'=>'product','text'=>(string)$p['name'],'page'=>$page];}
            $pagePlan[]=['type'=>'products','family'=>$family,'section'=>$section,'products'=>$chunk,'page'=>$page];
        }
    }
}

$tocPagePlans = [];
$entries = $tocEntries; $entryIndex = 0;
for($tocPage = 0; $tocPage < $tocPages; $tocPage++) {
    $pageNo = 2 + $tocPage;
    $y = 748;
    $pageEntries = [];
    if($tocPage === 0) { $y -= 40; }
    while($entryIndex < count($entries)) {
        $e = $entries[$entryIndex];
        $isFamily = $e['type'] === 'family';
        $topGap = ($isFamily && $y < 690) ? 16 : 0;
        $step = $isFamily ? 18 : 14;
        if($y - $topGap - $step < 52) break;
        $y -= $topGap;
        $pageEntries[] = $e;
        $y -= $step;
        $entryIndex++;
    }
    $tocPagePlans[] = ['page' => $pageNo, 'is_first' => ($tocPage === 0), 'entries' => $pageEntries];
}

page_header('Produktkatalog');
?>
<div class="card">
  <form method="get" class="catalog-options">
    <label>Pristype
      <select name="price" onchange="this.form.submit()">
        <option value="wholesale" <?=$price==='wholesale'?'selected':''?>>Engrospris ekskl. moms</option>
        <option value="retail" <?=$price==='retail'?'selected':''?>>Vejl. pris inkl. moms</option>
      </select>
    </label>
    <label>Katalogtype
      <select name="news" onchange="this.form.submit()">
        <option value="0" <?=$newOnly?'':'selected'?>>Hele kataloget</option>
        <option value="1" <?=$newOnly?'selected':''?>>Kun nyheder (Nyhedskatalog)</option>
      </select>
    </label>
    <div class="actions" style="margin-left:auto">
      <a class="button" href="catalog_pdf.php?price=wholesale&new_only=<?=$newOnly?1:0?>">Engrospris PDF</a>
      <a class="button" href="catalog_pdf.php?price=retail&new_only=<?=$newOnly?1:0?>">Vejl. pris PDF</a>
      <?php if(is_admin()):?>
        <a class="button secondary" href="products.php">Rediger produkter</a>
        <a class="button secondary" href="brands.php">Rediger brands</a>
      <?php endif;?>
    </div>
  </form>
</div>

<div class="catalog-document-preview">
  <section class="catalog-cover-preview" id="page-1">
    <img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky">
    <h1><?=h($catalogTitle)?></h1><p>Fra</p><h2>HSG Whisky Aps</h2><small>Opdateret <?=h(date('d. m. Y'))?></small>
  </section>

<?php foreach($tocPagePlans as $tocPlan):?>
  <section class="catalog-toc-page" id="page-<?=$tocPlan['page']?>">
    <div class="catalog-doc-header">
      <h2><?=$tocPlan['is_first'] ? 'Indhold' : ''?></h2>
      <img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky">
    </div>
    <div class="catalog-toc-list">
      <?php foreach($tocPlan['entries'] as $e): $isFamily = $e['type'] === 'family'; ?>
        <a href="#page-<?=$e['page']?>" class="catalog-toc-item <?=$isFamily?'family':'product'?>">
          <span class="catalog-toc-text"><?=h($e['text'])?></span>
          <span class="catalog-toc-dots"></span>
          <span class="catalog-toc-page-no"><?=$e['page']?></span>
        </a>
      <?php endforeach;?>
    </div>
    <div class="catalog-page-number"><?=$tocPlan['page']?></div>
  </section>
<?php endforeach;?>

<?php foreach($pagePlan as $plan):?>
  <?php if($plan['type']==='intro'):
    $family=(string)$plan['family'];
    $logo=hsg_catalog_logo_url($family,(string)($plan['logo']??''));
    $desc=trim((string)$plan['desc']);
  ?>
    <section class="catalog-brand-page" id="page-<?=$plan['page']?>">
      <div class="catalog-doc-header"><h2><?=h($plan['display'])?></h2><img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky"></div>
      <?php if($logo):?><img class="catalog-brand-main-logo" src="<?=h($logo)?>" alt="<?=h($plan['display'])?>"><?php endif;?>
      <?php if($desc!==''):?><p class="catalog-brand-description"><?=nl2br(h($desc))?></p><?php endif;?>
      <div class="catalog-page-number"><?=$plan['page']?></div>
    </section>
  <?php else:?>
    <section class="catalog-product-page" id="page-<?=$plan['page']?>">
      <div class="catalog-doc-header"><h2><?=h($plan['section'])?></h2><img src="<?=h(hsg_catalog_hsg_logo_url())?>" alt="HSG Whisky"></div>
      <?php foreach($plan['products'] as $i=>$p): $dataRows=hsg_catalog_product_rows($p,$field,$label); ?>
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
      <div class="catalog-page-number"><?=$plan['page']?></div>
    </section>
  <?php endif;?>
<?php endforeach;?>
</div>
<?php if(!$rows):?><div class="card"><p>Ingen produkter med disponibelt lager er tilgængelige i kataloget.</p></div><?php endif;?>
<?php page_footer();
