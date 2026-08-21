<?php
declare(strict_types=1);

function hsg_quality_field_definitions(): array {
    return [
        'image'=>['label'=>'Godkendt billede','group'=>'image'],
        'brand_id'=>['label'=>'Brand','group'=>'data'],
        'category'=>['label'=>'Kategori','group'=>'data'],
        'distillery'=>['label'=>'Destilleri','group'=>'data'],
        'country'=>['label'=>'Land','group'=>'data'],
        'age_text'=>['label'=>'Alder','group'=>'data'],
        'vintage_year'=>['label'=>'Årgang','group'=>'data'],
        'abv'=>['label'=>'ABV','group'=>'data'],
        'bottle_size_cl'=>['label'=>'Flaskestørrelse','group'=>'data'],
        'cask_type'=>['label'=>'Fadtype','group'=>'data'],
        'cask_number'=>['label'=>'Fadnummer','group'=>'data'],
        'wholesale_price'=>['label'=>'Engrospris','group'=>'data'],
        'retail_price'=>['label'=>'Udsalgspris','group'=>'data'],
    ];
}

function hsg_quality_default_required_fields(): array {
    return ['image','brand_id','distillery','abv','cask_type','cask_number','wholesale_price','retail_price'];
}

function hsg_quality_required_fields(PDO $pdo): array {
    $defs=hsg_quality_field_definitions();
    $raw=trim((string)setting_get($pdo,'quality_required_fields',''));
    if($raw==='') return hsg_quality_default_required_fields();
    $decoded=json_decode($raw,true);
    if(!is_array($decoded)) return hsg_quality_default_required_fields();
    $out=[];foreach($decoded as $key){$key=(string)$key;if(isset($defs[$key])&&!in_array($key,$out,true))$out[]=$key;}
    return $out;
}

function hsg_quality_save_required_fields(PDO $pdo,array $fields): void {
    $defs=hsg_quality_field_definitions();$clean=[];
    foreach($fields as $key){$key=(string)$key;if(isset($defs[$key])&&!in_array($key,$clean,true))$clean[]=$key;}
    setting_set($pdo,'quality_required_fields',json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function hsg_quality_exemptions(PDO $pdo,int $productId): array {
    if(!db_table_exists($pdo,'hsg_product_field_exemptions'))return [];
    $st=$pdo->prepare('SELECT field_key,reason,created_at,created_by_admin FROM hsg_product_field_exemptions WHERE product_id=?');$st->execute([$productId]);
    $out=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['field_key']]=$r;return $out;
}

function hsg_quality_field_missing(array $product,string $key): bool {
    if($key==='image'){
        // Out-of-stock products do not require image review. If stock returns, image becomes required again.
        if((int)($product['available']??0)<=0)return false;
        $path=trim((string)($product['image_path']??''));
        return $path==='' || (string)($product['image_approval_status']??'')!=='approved';
    }
    $v=$product[$key]??null;
    if(in_array($key,['brand_id','vintage_year'],true))return $v===null || (int)$v<=0;
    if(in_array($key,['abv','bottle_size_cl','wholesale_price','retail_price'],true))return $v===null || (float)$v<=0;
    return trim((string)$v)==='';
}

function hsg_quality_state(PDO $pdo,array $product,?array $required=null,?array $exemptions=null): array {
    $defs=hsg_quality_field_definitions();$required=$required??hsg_quality_required_fields($pdo);$exemptions=$exemptions??hsg_quality_exemptions($pdo,(int)$product['id']);
    $missing=[];$missingData=[];$missingImage=[];$exempted=[];
    foreach($required as $key){
        if(!isset($defs[$key]))continue;
        if(isset($exemptions[$key])){$exempted[$key]=$defs[$key];continue;}
        if(hsg_quality_field_missing($product,$key)){
            $missing[$key]=$defs[$key];
            if($defs[$key]['group']==='image')$missingImage[$key]=$defs[$key];else $missingData[$key]=$defs[$key];
        }
    }
    $approvedAt=trim((string)($product['quality_approved_at']??''));
    return [
        'missing'=>$missing,'missing_data'=>$missingData,'missing_image'=>$missingImage,'exempted'=>$exempted,
        'complete'=>count($missing)===0,'approved'=>count($missing)===0&&$approvedAt!=='',
        'ready'=>count($missing)===0&&$approvedAt===''
    ];
}

function hsg_quality_product_rows(PDO $pdo): array {
    $sql="SELECT p.*,b.name brand_name,a.display_name quality_approved_by_name,
      COALESCE(st.physical,0) physical_total,COALESCE(rs.reserved,0) reserved_total,COALESCE(rs.active_reservations,0) active_reservations,
      (COALESCE(st.physical,0)-COALESCE(rs.reserved,0)) available
      FROM lager_products p
      LEFT JOIN lager_brands b ON b.id=p.brand_id
      LEFT JOIN lager_admins a ON a.id=p.quality_approved_by_admin
      LEFT JOIN (SELECT product_id,SUM(quantity) physical FROM lager_stock GROUP BY product_id) st ON st.product_id=p.id
      LEFT JOIN (SELECT product_id,SUM(quantity) reserved,COUNT(*) active_reservations FROM lager_reservations WHERE status='reserved' GROUP BY product_id) rs ON rs.product_id=p.id
      WHERE p.status='active' ORDER BY p.name";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function hsg_quality_summary(PDO $pdo): array {
    $required=hsg_quality_required_fields($pdo);$rows=hsg_quality_product_rows($pdo);
    $summary=['total'=>count($rows),'needs_action'=>0,'missing_data'=>0,'missing_image'=>0,'ready'=>0,'approved'=>0];
    foreach($rows as $r){$s=hsg_quality_state($pdo,$r,$required);if($s['missing'])$summary['needs_action']++;if($s['missing_data'])$summary['missing_data']++;if($s['missing_image'])$summary['missing_image']++;if($s['ready'])$summary['ready']++;if($s['approved'])$summary['approved']++;}
    return $summary;
}

function hsg_quality_invalidate(PDO $pdo,int $productId): void {
    if(!db_column_exists($pdo,'lager_products','quality_approved_at'))return;
    $pdo->prepare('UPDATE lager_products SET quality_approved_at=NULL,quality_approved_by_admin=NULL WHERE id=?')->execute([$productId]);
}
