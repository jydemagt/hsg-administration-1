<?php
require __DIR__.'/auth.php';require_capability('inventory.view');
$q=trim($_GET['q']??'');$locs=get_locations($pdo,true);$params=[];$where="p.status='active'";if($q!==''){$where.=' AND (p.name LIKE ? OR p.sku LIKE ? OR b.name LIKE ?)';$params=array_fill(0,3,'%'.$q.'%');}
$sql="SELECT p.id,p.sku,p.name,p.image_path,p.image_approval_status,p.is_new,p.category,p.distillery,p.country,p.age_text,p.vintage_year,p.abv,p.bottle_size_cl,p.cask_type,p.cask_number,p.bottle_count,p.wholesale_price,p.retail_price,p.notes,b.name brand_name,COALESCE(SUM(s.quantity),0) physical,COALESCE(SUM(r.reserved),0) reserved,COALESCE(SUM(s.quantity),0)-COALESCE(SUM(r.reserved),0) available FROM lager_products p LEFT JOIN lager_brands b ON b.id=p.brand_id LEFT JOIN lager_stock s ON s.product_id=p.id LEFT JOIN (SELECT product_id,location_id,SUM(quantity) reserved FROM lager_reservations WHERE status='reserved' GROUP BY product_id,location_id) r ON r.product_id=p.id AND r.location_id=s.location_id WHERE $where GROUP BY p.id HAVING (COALESCE(SUM(s.quantity),0)-COALESCE(SUM(r.reserved),0)) > 0 ORDER BY p.name";$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();

$stockBy = [];
if($rows) {
  $productIds = array_column($rows, 'id');
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $stBatch = $pdo->prepare("
    SELECT l.id location_id, l.name location_name, p.id product_id,
           COALESCE(s.quantity,0) physical,
           COALESCE(r.qty,0) reserved,
           COALESCE(s.quantity,0)-COALESCE(r.qty,0) available
    FROM lager_locations l
    CROSS JOIN lager_products p
    LEFT JOIN lager_stock s ON s.location_id=l.id AND s.product_id=p.id
    LEFT JOIN (
      SELECT product_id, location_id, SUM(quantity) qty
      FROM lager_reservations
      WHERE status='reserved'
      GROUP BY product_id, location_id
    ) r ON r.location_id=l.id AND r.product_id=p.id
    WHERE l.active=1 AND p.id IN ($placeholders)
    ORDER BY l.sort_order, l.name
  ");
  $stBatch->execute($productIds);
  $batchRows = $stBatch->fetchAll(PDO::FETCH_ASSOC);
  foreach($batchRows as $br) {
    $pId = (int)$br['product_id'];
    $stockBy[$pId][] = [
      'id' => (int)$br['location_id'],
      'name' => (string)$br['location_name'],
      'physical' => (int)$br['physical'],
      'reserved' => (int)$br['reserved'],
      'available' => (int)$br['available'],
    ];
  }
}

$productDetails=[];foreach($rows as $p){$id=(int)$p['id'];$productDetails[$id]=[
  'id'=>$id,'sku'=>(string)$p['sku'],'name'=>(string)$p['name'],'brand'=>(string)($p['brand_name']??''),'category'=>(string)($p['category']??''),'distillery'=>(string)($p['distillery']??''),'country'=>(string)($p['country']??''),'age'=>(string)($p['age_text']??''),'vintage'=>$p['vintage_year']!==null?(int)$p['vintage_year']:null,'abv'=>$p['abv']!==null?(float)$p['abv']:null,'bottle_size'=>$p['bottle_size_cl']!==null?(float)$p['bottle_size_cl']:null,'cask_type'=>(string)($p['cask_type']??''),'cask_number'=>(string)($p['cask_number']??''),'bottle_count'=>(string)($p['bottle_count']??''),'wholesale'=>$p['wholesale_price']!==null?(float)$p['wholesale_price']:null,'retail'=>$p['retail_price']!==null?(float)$p['retail_price']:null,'notes'=>(string)($p['notes']??''),'is_new'=>(bool)$p['is_new'],'image'=>product_image_url(($p['image_approval_status']??'')==='approved'?$p['image_path']:null),'physical'=>(int)$p['physical'],'reserved'=>(int)$p['reserved'],'available'=>(int)$p['available'],'locations'=>array_values($stockBy[$id]??[])
];}
page_header('Lagerstatus');
?>
<form class="searchbar" method="get"><input name="q" value="<?=h($q)?>" placeholder="Søg produkt, SKU eller brand"><button type="submit">Søg</button></form>
<div class="mobile-list"><?php foreach($rows as $p):?><article class="mobile-product"><div class="mobile-product-main product-detail-trigger" data-product-id="<?=$p['id']?>" role="button" tabindex="0" aria-label="Vis detaljer for <?=h($p['name'])?>"><img src="<?=h(product_image_url(($p['image_approval_status']??'')==='approved'?$p['image_path']:null))?>" alt="<?=h($p['name'])?>"><div><h3><?=h($p['name'])?> <?=$p['is_new']?'<span class="badge red">NYHED</span>':''?></h3><div class="sku"><?=h($p['sku'])?><?=!empty($p['brand_name'])?' · '.h($p['brand_name']):''?></div><?php foreach($stockBy[$p['id']]??[] as $l):?><div class="mobile-stock-line"><span><?=h($l['name'])?></span><strong><?=intval($l['available'])?> stk.</strong></div><?php endforeach;?><div class="mobile-stock-line total"><span>Disponibelt i alt</span><span class="available"><?=intval($p['available'])?> stk.</span></div><span class="product-detail-hint">Tryk for produktdetaljer</span></div></div><?php if(can('reservations.create')):?><button type="button" class="open-reserve reserve-list-button" data-product-id="<?=$p['id']?>">Reservér</button><?php endif;?></article><?php endforeach;?></div>
<div class="table-wrap mobile-hide"><table class="status-table"><thead><tr><th class="status-product-col">Produkt</th><?php foreach($locs as $l):?><th class="status-loc-col"><?=h($l['name'])?></th><?php endforeach;?><th class="status-num-col">Fysisk</th><th class="status-num-col">Reserveret</th><th class="status-num-col">Disponibelt</th><th class="status-action-col">Handling</th></tr></thead><tbody><?php foreach($rows as $p):?><tr><td class="status-product-col"><button type="button" class="product-row product-detail-trigger product-row-button" data-product-id="<?=$p['id']?>" aria-label="Vis detaljer for <?=h($p['name'])?>"><div class="product-thumb-frame"><img class="product-thumb" src="<?=h(product_image_url(($p['image_approval_status']??'')==='approved'?$p['image_path']:null))?>" alt="<?=h($p['name'])?>"></div><div><strong class="product-title"><?=h($p['name'])?></strong><div class="product-meta"><?=h($p['sku'])?><?=!empty($p['brand_name'])?' · '.h($p['brand_name']):''?></div></div></button></td><?php $map=[];foreach($stockBy[$p['id']]??[] as $x)$map[$x['id']]=$x;foreach($locs as $l):$x=$map[$l['id']]??['available'=>0];?><td class="status-loc-col"><?=intval($x['available'])?></td><?php endforeach;?><td class="status-num-col"><?=$p['physical']?></td><td class="status-num-col"><?=$p['reserved']?></td><td class="status-num-col available"><span class="badge green" style="font-size:13.5px; padding:3px 8px;"><?=$p['available']?> stk.</span></td><td class="status-action-col"><?php if(can('reservations.create')):?><button type="button" class="open-reserve" data-product-id="<?=$p['id']?>">Reservér</button><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rows):?>
<tr><td colspan="<?=count($locs)+5?>" class="muted" style="text-align:center; padding:24px;">Der blev ikke fundet nogen produkter på lager. <a class="button secondary small" href="status.php">Ryd søgning</a></td></tr>
<?php endif;?>
</tbody></table></div>

<?php if(can('reservations.create')):?><div id="reserveModal" class="hsg-modal" hidden aria-hidden="true"><div class="hsg-modal-backdrop" data-modal-close="reserve"></div><section class="hsg-modal-dialog reserve-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="reserveModalTitle"><button type="button" class="hsg-modal-close" data-modal-close="reserve" aria-label="Luk">×</button><div class="hsg-modal-head"><span class="modal-kicker">Ny reservation</span><h2 id="reserveModalTitle">Reservér</h2><p id="reserveModalMeta" class="muted"></p></div><form method="post" action="reservations.php" id="reserveModalForm"><?=csrf_field()?><input type="hidden" name="action" value="quick"><input type="hidden" name="product_id" id="reserveProductId"><label>Lokation<select name="location_id" id="reserveLocation" required></select></label><label>Hvor mange?<div class="qty-stepper"><button type="button" class="secondary qty-minus" aria-label="Træk én fra">−</button><input type="number" min="1" step="1" name="quantity" id="reserveQuantity" value="1" required inputmode="numeric"><button type="button" class="secondary qty-plus" aria-label="Læg én til">+</button></div><small class="muted" id="reserveAvailable"></small></label><label>Hvem / hvad er reservationen til?<textarea name="customer_name" id="reserveFor" maxlength="180" rows="4" placeholder="Fx Peter Hansen, smagning i Vejle, ordre #1047 ..."></textarea></label><button type="submit" class="reserve-submit">Reservér</button></form></section></div><?php endif;?>

<div id="productModal" class="hsg-modal" hidden aria-hidden="true"><div class="hsg-modal-backdrop" data-modal-close="product"></div><section class="hsg-modal-dialog product-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="productModalTitle"><button type="button" class="hsg-modal-close" data-modal-close="product" aria-label="Luk">×</button><div class="product-modal-layout"><div class="product-modal-image" id="productModalImageWrap" role="button" tabindex="0" title="Klik for at forstørre billede"><img id="productModalImage" src="" alt=""></div><div class="product-modal-body"><div class="product-modal-titleline"><div><span id="productModalNew" class="badge red" hidden>NYHED</span><h2 id="productModalTitle"></h2><p id="productModalSub" class="muted"></p></div><?php if(can('reservations.create')):?><button type="button" id="productModalReserve" class="open-reserve">Reservér</button><?php endif;?></div><div id="productModalFacts" class="product-detail-grid"></div><div class="product-stock-details"><h3>Lager</h3><div id="productModalStock"></div></div><div id="productModalNotesWrap" class="product-notes" hidden><h3>Bemærkning</h3><p id="productModalNotes"></p></div></div></div></section></div>

<div id="imageLightboxModal" class="hsg-lightbox" hidden aria-hidden="true"><div class="hsg-lightbox-backdrop" data-lightbox-close="1"></div><div class="hsg-lightbox-dialog" role="dialog" aria-modal="true" aria-label="Forstørret produktbillede"><button type="button" class="hsg-lightbox-close" data-lightbox-close="1" aria-label="Luk forstørret billede">×</button><img id="hsgLightboxImage" class="hsg-lightbox-img" src="" alt=""><div id="hsgLightboxCaption" class="hsg-lightbox-caption"></div></div></div>

<script>
const HSG_PRODUCTS=<?=json_encode($productDetails,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const money=v=>v===null||v===undefined?'—':new Intl.NumberFormat('da-DK',{style:'currency',currency:'DKK',maximumFractionDigits:2}).format(v);
const fmt=v=>v===null||v===undefined||v===''?'—':String(v).replace('.',',');
const reserveModal=document.getElementById('reserveModal'),productModal=document.getElementById('productModal'),lightboxModal=document.getElementById('imageLightboxModal');
let previousActiveElement = null;

function modalOpen(el){
  if(!el)return;
  previousActiveElement = document.activeElement;
  el.hidden=false;
  el.setAttribute('aria-hidden','false');
  document.body.classList.add('modal-open');
}
function modalClose(el){
  if(!el)return;
  el.hidden=true;
  el.setAttribute('aria-hidden','true');
  if((!reserveModal||reserveModal.hidden)&&(productModal.hidden)&&(!lightboxModal||lightboxModal.hidden))document.body.classList.remove('modal-open');
  if(previousActiveElement && typeof previousActiveElement.focus === 'function' && el !== lightboxModal){
    previousActiveElement.focus();
    previousActiveElement = null;
  }
}

function openLightbox(src, caption){
  if(!lightboxModal || !src) return;
  const img = document.getElementById('hsgLightboxImage');
  const cap = document.getElementById('hsgLightboxCaption');
  if(img) { img.src = src; img.alt = caption || 'Produktbillede'; }
  if(cap) { cap.textContent = caption || ''; cap.hidden = !caption; }
  lightboxModal.hidden = false;
  lightboxModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('modal-open');
}
function closeLightbox(){
  if(!lightboxModal) return;
  lightboxModal.hidden = true;
  lightboxModal.setAttribute('aria-hidden', 'true');
  if((!reserveModal||reserveModal.hidden) && productModal.hidden) document.body.classList.remove('modal-open');
}
function productById(id){return HSG_PRODUCTS[String(id)]||HSG_PRODUCTS[Number(id)]||null;}
function openReserve(id){
  const p=productById(id);if(!p||!reserveModal)return;
  document.getElementById('reserveProductId').value=p.id;
  document.getElementById('reserveModalTitle').textContent=p.name;
  document.getElementById('reserveModalMeta').textContent=[p.sku,p.brand].filter(Boolean).join(' · ');
  const sel=document.getElementById('reserveLocation');
  sel.textContent='';
  p.locations.filter(l=>l.available>0).forEach(l=>{
    const o=document.createElement('option');
    o.value=l.id;
    o.textContent=`${l.name} (${l.available} stk. disponible)`;
    o.dataset.available=l.available;
    sel.appendChild(o);
  });
  document.getElementById('reserveQuantity').value='1';
  document.getElementById('reserveFor').value='';
  updateReserveMax();
  modalOpen(reserveModal);
  setTimeout(()=>document.getElementById('reserveQuantity').focus(),50);
}
function updateReserveMax(){
  const sel=document.getElementById('reserveLocation'),qty=document.getElementById('reserveQuantity'),o=sel?.selectedOptions?.[0],max=Number(o?.dataset?.available||1);
  qty.max=String(Math.max(1,max));
  if(Number(qty.value)>max)qty.value=String(max);
  document.getElementById('reserveAvailable').textContent=`Maks. ${max} stk. på valgt lokation`;
}
function addFactDOM(parent, label, value){
  if(value===null||value===undefined||value==='')return;
  const div = document.createElement('div');
  div.className = 'product-detail-fact';
  const span = document.createElement('span');
  span.textContent = label;
  const strong = document.createElement('strong');
  strong.textContent = String(value);
  div.appendChild(span);
  div.appendChild(strong);
  parent.appendChild(div);
}
function openProduct(id){
  const p=productById(id);if(!p)return;
  document.getElementById('productModalTitle').textContent=p.name;
  document.getElementById('productModalSub').textContent=[p.sku,p.brand].filter(Boolean).join(' · ');
  const img=document.getElementById('productModalImage');
  img.src=p.image;img.alt=p.name;
  const elNew=document.getElementById('productModalNew');
  if(elNew){
    elNew.hidden=!p.is_new;
    elNew.style.display=p.is_new?'inline-flex':'none';
  }
  const factsBox = document.getElementById('productModalFacts');
  factsBox.textContent = '';
  addFactDOM(factsBox, 'Destilleri', p.distillery);
  addFactDOM(factsBox, 'Kategori', p.category);
  addFactDOM(factsBox, 'Land', p.country);
  addFactDOM(factsBox, 'Årgang', p.vintage);
  addFactDOM(factsBox, 'Alder', p.age);
  addFactDOM(factsBox, 'Alkohol', p.abv!==null?fmt(p.abv)+' %':null);
  addFactDOM(factsBox, 'Flaskestørrelse', p.bottle_size!==null?fmt(p.bottle_size)+' cl':null);
  addFactDOM(factsBox, 'Fadnummer', p.cask_number);
  addFactDOM(factsBox, 'Fadtype', p.cask_type);
  addFactDOM(factsBox, 'Antal flasker', p.bottle_count);
  addFactDOM(factsBox, 'Engrospris', p.wholesale!==null?money(p.wholesale):null);
  addFactDOM(factsBox, 'Udsalgspris', p.retail!==null?money(p.retail):null);

  if(!factsBox.hasChildNodes()){
    const empty = document.createElement('span');
    empty.className = 'muted';
    empty.textContent = 'Ingen yderligere produktdata registreret.';
    factsBox.appendChild(empty);
  }

  const stockBox = document.getElementById('productModalStock');
  stockBox.textContent = '';
  p.locations.forEach(l => {
    const row = document.createElement('div');
    row.className = 'product-stock-detail';

    const sName = document.createElement('span');
    sName.textContent = l.name;

    const sPhys = document.createElement('span');
    sPhys.textContent = l.physical + ' fysisk';

    const sRes = document.createElement('span');
    sRes.textContent = l.reserved + ' reserveret';

    const sAvail = document.createElement('strong');
    sAvail.textContent = l.available + ' disponible';

    row.appendChild(sName);
    row.appendChild(sPhys);
    row.appendChild(sRes);
    row.appendChild(sAvail);
    stockBox.appendChild(row);
  });

  const totRow = document.createElement('div');
  totRow.className = 'product-stock-detail total';

  const tName = document.createElement('span');
  tName.textContent = 'I alt';

  const tPhys = document.createElement('span');
  tPhys.textContent = p.physical + ' fysisk';

  const tRes = document.createElement('span');
  tRes.textContent = p.reserved + ' reserveret';

  const tAvail = document.createElement('strong');
  tAvail.textContent = p.available + ' disponible';

  totRow.appendChild(tName);
  totRow.appendChild(tPhys);
  totRow.appendChild(tRes);
  totRow.appendChild(tAvail);
  stockBox.appendChild(totRow);

  const nw=document.getElementById('productModalNotesWrap');
  nw.hidden=!p.notes;
  document.getElementById('productModalNotes').textContent=p.notes||'';
  const rb=document.getElementById('productModalReserve');
  if(rb)rb.dataset.productId=p.id;
  modalOpen(productModal);
}
document.addEventListener('click',e=>{
  const reserve=e.target.closest('.open-reserve');
  if(reserve){e.preventDefault();e.stopPropagation();const id=reserve.dataset.productId;if(productModal&&!productModal.hidden)modalClose(productModal);openReserve(id);return;}
  const product=e.target.closest('.product-detail-trigger');
  if(product){e.preventDefault();openProduct(product.dataset.productId);}
});
document.addEventListener('keydown',e=>{const product=e.target.closest?.('.product-detail-trigger');if(product&&(e.key==='Enter'||e.key===' ')){e.preventDefault();openProduct(product.dataset.productId);}});
document.querySelectorAll('[data-modal-close="reserve"]').forEach(b=>b.addEventListener('click',()=>modalClose(reserveModal)));
document.querySelectorAll('[data-modal-close="product"]').forEach(b=>b.addEventListener('click',()=>modalClose(productModal)));
document.querySelectorAll('[data-lightbox-close="1"]').forEach(b=>b.addEventListener('click',closeLightbox));

const imgWrap = document.getElementById('productModalImageWrap');
if(imgWrap){
  imgWrap.addEventListener('click', ()=>{
    const img = document.getElementById('productModalImage');
    const title = document.getElementById('productModalTitle')?.textContent || '';
    if(img && img.src && !img.src.includes('placeholder')) {
      openLightbox(img.src, title);
    }
  });
  imgWrap.addEventListener('keydown', (e)=>{
    if(e.key === 'Enter' || e.key === ' '){
      e.preventDefault();
      imgWrap.click();
    }
  });
}
const loc=document.getElementById('reserveLocation');if(loc)loc.addEventListener('change',updateReserveMax);
document.querySelector('.qty-minus')?.addEventListener('click',()=>{const q=document.getElementById('reserveQuantity');q.value=String(Math.max(1,Number(q.value||1)-1));});
document.querySelector('.qty-plus')?.addEventListener('click',()=>{const q=document.getElementById('reserveQuantity'),m=Number(q.max||999);q.value=String(Math.min(m,Number(q.value||1)+1));});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(lightboxModal&&!lightboxModal.hidden)closeLightbox();else if(reserveModal&&!reserveModal.hidden)modalClose(reserveModal);else if(!productModal.hidden)modalClose(productModal);}});
</script>
<?php page_footer();
