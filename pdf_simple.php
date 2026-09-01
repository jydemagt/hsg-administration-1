<?php
declare(strict_types=1);
final class SimplePdf {
 private array $pages=[];
 private array $links=[];
 private array $fontMap=['helvetica'=>'F1','helvetica-bold'=>'F2','times'=>'F3','times-bold'=>'F4'];
 public function addPage(array $ops,array $images=[]): void {$this->pages[]=['ops'=>$ops,'images'=>$images];}
 public function addLink(int $fromPage, float $x1, float $y1, float $x2, float $y2, int $toPage): void {
  $this->links[]=['from'=>max(1,$fromPage),'to'=>max(1,$toPage),'x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2];
 }
 private function esc(string $s): string {$s=iconv('UTF-8','Windows-1252//TRANSLIT',$s)?:$s;return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);}
 private function imgObj(string $path): ?array {
  if(!is_file($path))return null;
  $info=@getimagesize($path);
  if(!$info)return null;
  if($info[2]===IMAGETYPE_PNG && function_exists('imagecreatefrompng') && function_exists('imagejpeg')){
   $cacheDir=__DIR__.'/storage/tmp/pdf-cache';
   if(!is_dir($cacheDir))@mkdir($cacheDir,0775,true);
   $cachedJpeg=$cacheDir.'/png-'.md5($path.(string)@filemtime($path)).'.jpg';
   if(!is_file($cachedJpeg)){
    $src=@imagecreatefrompng($path);
    if($src){
     $w=imagesx($src);$h=imagesy($src);
     $bg=imagecreatetruecolor($w,$h);$white=imagecolorallocate($bg,255,255,255);imagefill($bg,0,0,$white);
     imagecopyresampled($bg,$src,0,0,0,0,$w,$h,$w,$h);imagejpeg($bg,$cachedJpeg,95);
     imagedestroy($bg);imagedestroy($src);
    }
   }
   if(is_file($cachedJpeg)){$path=$cachedJpeg;$info=@getimagesize($path);}
  }
  if(!$info||$info[2]!==IMAGETYPE_JPEG)return null;
  $data=file_get_contents($path);
  if($data===false)return null;
  return ['dict'=>'<< /Type /XObject /Subtype /Image /Width '.$info[0].' /Height '.$info[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($data).' >>','stream'=>$data,'w'=>$info[0],'h'=>$info[1]];
 }
 public function text(float $x,float $y,float $size,string $text,bool $bold=false): string {$font=$bold?'F2':'F1';return 'BT /'.$font.' '.$size.' Tf '.$x.' '.$y.' Td ('.$this->esc($text).') Tj ET';}
 public function textFont(float $x,float $y,float $size,string $text,string $font='helvetica'): string {$f=$this->fontMap[strtolower($font)]??'F1';return 'BT /'.$f.' '.$size.' Tf '.$x.' '.$y.' Td ('.$this->esc($text).') Tj ET';}
 public function dottedLine(float $x1,float $y,float $x2,float $w=.5): string {return '[1.2 2] 0 d '.$w.' w '.$x1.' '.$y.' m '.$x2.' '.$y.' l S [] 0 d';}
 public function line(float $x1,float $y1,float $x2,float $y2,float $w=.5): string {return $w.' w '.$x1.' '.$y1.' m '.$x2.' '.$y2.' l S';}
 public function rect(float $x,float $y,float $w,float $h,bool $fill=false): string {return $x.' '.$y.' '.$w.' '.$h.' re '.($fill?'f':'S');}
 public function setRgb(float $r,float $g,float $b,bool $fill=true): string {return $r.' '.$g.' '.$b.' '.($fill?'rg':'RG');}
 public function wrap(string $text,int $chars): array {$words=preg_split('/\s+/u',trim($text))?:[];$lines=[];$line='';foreach($words as $w){$try=$line===''?$w:$line.' '.$w;if((function_exists('mb_strlen')?mb_strlen($try,'UTF-8'):strlen($try))>$chars&&$line!==''){$lines[]=$line;$line=$w;}else$line=$try;}if($line!=='')$lines[]=$line;return $lines;}
 public function output(string $filename): never {
  $objs=[];$objs[1]='<< /Type /Catalog /Pages 2 0 R >>';$objs[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';$objs[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';$objs[5]='<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>';$objs[6]='<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>';$next=7;$kids=[];
  foreach($this->pages as $page){$xobjs=[];$imageRefs=[];$imgIndex=0;foreach($page['images'] as $name=>$path){$img=$this->imgObj($path);if(!$img)continue;$obj=$next++;$objs[$obj]=$img['dict']."\nstream\n".$img['stream']."\nendstream";$xobjs[]='/'.$name.' '.$obj.' 0 R';$imageRefs[$name]=$img;}$content=implode("\n",$page['ops']);$contentObj=$next++;$objs[$contentObj]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."\nendstream";$pageObj=$next++;$res='<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>'.($xobjs?' /XObject << '.implode(' ',$xobjs).' >>':'').' >>';$objs[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources '.$res.' /Contents '.$contentObj.' 0 R >>';$kids[]=$pageObj.' 0 R';}
  $pageObjMap=[];
  $nextObj=7;
  foreach($this->pages as $idx=>$page){
      $pageNum=$idx+1;
      $imgCount=0;
      foreach($page['images'] as $name=>$path){
          if($this->imgObj($path))$imgCount++;
      }
      $nextObj+=$imgCount+1; // images + contentObj
      $pageObjMap[$pageNum]=$nextObj++;
  }

  $linksByPage=[];
  foreach($this->links as $l){
      $from=$l['from'];$to=$l['to'];
      if(!isset($pageObjMap[$to])) continue;
      $targetPageObj=$pageObjMap[$to];
      $annotObj=$nextObj++;
      $objs[$annotObj]=sprintf('<< /Type /Annot /Subtype /Link /Rect [%.2f %.2f %.2f %.2f] /Border [0 0 0] /Dest [%d 0 R /FitH 790] >>',$l['x1'],$l['y1'],$l['x2'],$l['y2'],$targetPageObj);
      $linksByPage[$from][]=$annotObj.' 0 R';
  }

  $next=7;$kids=[];
  foreach($this->pages as $idx=>$page){
      $pageNum=$idx+1;
      $xobjs=[];$imageRefs=[];
      foreach($page['images'] as $name=>$path){
          $img=$this->imgObj($path);if(!$img)continue;
          $obj=$next++;$objs[$obj]=$img['dict']."\nstream\n".$img['stream']."\nendstream";
          $xobjs[]='/'.$name.' '.$obj.' 0 R';$imageRefs[$name]=$img;
      }
      $content=implode("\n",$page['ops']);$contentObj=$next++;
      $objs[$contentObj]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."\nendstream";
      $pageObj=$next++;
      $res='<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>'.($xobjs?' /XObject << '.implode(' ',$xobjs).' >>':'').' >>';
      $annotsRef=!empty($linksByPage[$pageNum])?' /Annots ['.implode(' ',$linksByPage[$pageNum]).']':'';
      $objs[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources '.$res.$annotsRef.' /Contents '.$contentObj.' 0 R >>';
      $kids[]=$pageObj.' 0 R';
  }
  $objs[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objs);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offset=[0=>0];foreach($objs as $num=>$body){$offset[$num]=strlen($pdf);$pdf.=$num." 0 obj\n".$body."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objs));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',(int)($offset[$i]??0))."\n";$pdf.='trailer << /Size '.($max+1).' /Root 1 0 R >>' . "\nstartxref\n$xref\n%%EOF";header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
 }
}
