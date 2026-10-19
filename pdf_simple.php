<?php
declare(strict_types=1);
final class SimplePdf {
 private array $pages=[];
 private array $links=[];
 private array $fontMap=['helvetica'=>'F1','helvetica-bold'=>'F2','times'=>'F3','times-bold'=>'F4'];
 /** @var resource|null */
 private $out=null;
 private int $pos=0;
 private array $offsets=[];
 private int $nextObj=7;
 private ?int $blankImageObj=null;
 // PNG alpha is decoded pixel by pixel in PHP; refuse absurdly large files instead of exhausting memory.
 private const MAX_PNG_PIXELS=9000000;
 public function addPage(array $ops,array $images=[]): void {$this->pages[]=['ops'=>$ops,'images'=>$images];}
 public function addLink(int $fromPage, float $x1, float $y1, float $x2, float $y2, int $toPage): void {
  $this->links[]=['from'=>max(1,$fromPage),'to'=>max(1,$toPage),'x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2];
 }
 private function esc(string $s): string {
  if (function_exists('iconv')) {
   $s = iconv('UTF-8', 'Windows-1252//TRANSLIT', $s) ?: $s;
  } elseif (function_exists('mb_convert_encoding')) {
   $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
  }
  return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);
 }
 private function write(string $s): void {fwrite($this->out,$s);$this->pos+=strlen($s);}
 private function emit(int $num,string $body): void {$this->offsets[$num]=$this->pos;$this->write($num." 0 obj\n".$body."\nendobj\n");}
 private function emitStream(int $num,string $dict,string $stream): void {$this->offsets[$num]=$this->pos;$this->write($num." 0 obj\n".$dict."\nstream\n");$this->write($stream);$this->write("\nendstream\nendobj\n");}
 /** Writes the image as an XObject and returns its object number, or null if it cannot be embedded. */
 private function writeImage(string $path): ?int {
  if(!is_file($path))return null;
  $info=@getimagesize($path);
  if(!$info||$info[0]<=0||$info[1]<=0)return null;

  // Handle PNG images with 8-bit Alpha channel using PDF /SMask Soft Mask
  if($info[2]===IMAGETYPE_PNG){
   if(!function_exists('imagecreatefrompng')||!function_exists('gzcompress')||$info[0]*$info[1]>self::MAX_PNG_PIXELS)return null;
   try {
    $src=@imagecreatefrompng($path);
    if(!$src)return null;
    if(!imageistruecolor($src))imagepalettetotruecolor($src);
    $w=imagesx($src);$h=imagesy($src);
    $rgbData='';$alphaData='';
    for($y=0;$y<$h;$y++){
     for($x=0;$x<$w;$x++){
      $rgba=imagecolorat($src,$x,$y);
      // Convert 7-bit GD alpha (0=opaque, 127=transparent) to PDF 8-bit alpha (255=opaque, 0=transparent)
      $alphaData.=chr((int)round((127-(($rgba>>24)&0x7F))*255/127));
      $rgbData.=chr(($rgba>>16)&0xFF).chr(($rgba>>8)&0xFF).chr($rgba&0xFF);
     }
    }
    unset($src);
   } catch(Throwable $e) {
    // Fail gracefully to the blank placeholder if GD image processing throws an exception
    return null;
   }
   $alphaStream=gzcompress($alphaData);unset($alphaData);
   $smaskObj=$this->nextObj++;
   $this->emitStream($smaskObj,'<< /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h.' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($alphaStream).' >>',$alphaStream);
   unset($alphaStream);
   $rgbStream=gzcompress($rgbData);unset($rgbData);
   $imgObj=$this->nextObj++;
   $this->emitStream($imgObj,'<< /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask '.$smaskObj.' 0 R /Length '.strlen($rgbStream).' >>',$rgbStream);
   return $imgObj;
  }

  if($info[2]!==IMAGETYPE_JPEG)return null;
  $data=@file_get_contents($path);
  if($data===false||$data==='')return null;
  // Logos are often grayscale or CMYK; declaring them as RGB makes viewers fail on the page.
  $channels=(int)($info['channels']??3);
  $cs=match($channels){1=>'/DeviceGray',4=>'/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]',default=>'/DeviceRGB'};
  $obj=$this->nextObj++;
  $this->emitStream($obj,'<< /Type /XObject /Subtype /Image /Width '.$info[0].' /Height '.$info[1].' /ColorSpace '.$cs.' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($data).' >>',$data);
  return $obj;
 }
 /** 1x1 white image used when a referenced image cannot be embedded, so the page never points at a missing resource. */
 private function blankImage(): int {
  if($this->blankImageObj===null){$this->blankImageObj=$this->nextObj++;$this->emitStream($this->blankImageObj,'<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>',"\xFF");}
  return $this->blankImageObj;
 }
 public function text(float $x,float $y,float $size,string $text,bool $bold=false): string {$font=$bold?'F2':'F1';return 'BT /'.$font.' '.$size.' Tf '.$x.' '.$y.' Td ('.$this->esc($text).') Tj ET';}
 public function textFont(float $x,float $y,float $size,string $text,string $font='helvetica'): string {$f=$this->fontMap[strtolower($font)]??'F1';return 'BT /'.$f.' '.$size.' Tf '.$x.' '.$y.' Td ('.$this->esc($text).') Tj ET';}
 public function dottedLine(float $x1,float $y,float $x2,float $w=.5): string {return '[1.2 2] 0 d '.$w.' w '.$x1.' '.$y.' m '.$x2.' '.$y.' l S [] 0 d';}
 public function line(float $x1,float $y1,float $x2,float $y2,float $w=.5): string {return $w.' w '.$x1.' '.$y1.' m '.$x2.' '.$y2.' l S';}
 public function rect(float $x,float $y,float $w,float $h,bool $fill=false): string {return $x.' '.$y.' '.$w.' '.$h.' re '.($fill?'f':'S');}
 public function setRgb(float $r,float $g,float $b,bool $fill=true): string {return $r.' '.$g.' '.$b.' '.($fill?'rg':'RG');}
 public function wrap(string $text,int $chars): array {$words=preg_split('/\s+/u',trim($text))?:[];$lines=[];$line='';foreach($words as $w){$try=$line===''?$w:$line.' '.$w;if((function_exists('mb_strlen')?mb_strlen($try,'UTF-8'):strlen($try))>$chars&&$line!==''){$lines[]=$line;$line=$w;}else$line=$try;}if($line!=='')$lines[]=$line;return $lines;}
 public function output(string $filename, string $disposition='attachment'): void {
  // The document is streamed to a temp stream (spills to disk) object by object, so memory use
  // stays flat no matter how many product images the catalogue contains.
  $this->out=fopen('php://temp/maxmemory:'.(4*1024*1024),'w+b');$this->pos=0;$this->offsets=[];$this->nextObj=7;$this->blankImageObj=null;
  $this->write("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
  $this->emit(1,'<< /Type /Catalog /Pages 2 0 R >>');
  $this->emit(3,'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
  $this->emit(4,'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
  $this->emit(5,'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>');
  $this->emit(6,'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>');

  // Page object numbers are reserved up front so TOC links can point forward to any page.
  $pageObjMap=[];
  foreach($this->pages as $idx=>$_)$pageObjMap[$idx+1]=$this->nextObj++;

  $linksByPage=[];
  foreach($this->links as $l){
      if(!isset($pageObjMap[$l['to']])) continue;
      $annotObj=$this->nextObj++;
      $this->emit($annotObj,sprintf('<< /Type /Annot /Subtype /Link /Rect [%.2f %.2f %.2f %.2f] /Border [0 0 0] /Dest [%d 0 R /FitH 790] >>',$l['x1'],$l['y1'],$l['x2'],$l['y2'],$pageObjMap[$l['to']]));
      $linksByPage[$l['from']][]=$annotObj.' 0 R';
  }

  // Each image file is embedded once and shared by every page that uses it (logo, NYHED badge).
  $imageObjs=[];$kids=[];
  foreach($this->pages as $idx=>$page){
      $pageNum=$idx+1;
      $xobjs=[];
      foreach($page['images'] as $name=>$path){
          if(!array_key_exists($path,$imageObjs))$imageObjs[$path]=$this->writeImage((string)$path);
          $xobjs[]='/'.$name.' '.($imageObjs[$path]??$this->blankImage()).' 0 R';
      }
      $content=implode("\n",$page['ops']);$contentObj=$this->nextObj++;
      $this->emitStream($contentObj,'<< /Length '.strlen($content).' >>',$content);
      $res='<< /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >>'.($xobjs?' /XObject << '.implode(' ',$xobjs).' >>':'').' >>';
      $annotsRef=!empty($linksByPage[$pageNum])?' /Annots ['.implode(' ',$linksByPage[$pageNum]).']':'';
      $this->emit($pageObjMap[$pageNum],'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources '.$res.$annotsRef.' /Contents '.$contentObj.' 0 R >>');
      $kids[]=$pageObjMap[$pageNum].' 0 R';
  }
  $this->emit(2,'<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>');

  $xref=$this->pos;$max=$this->nextObj-1;
  $table="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=$max;$i++)$table.=isset($this->offsets[$i])?sprintf('%010d 00000 n ',$this->offsets[$i])."\n":"0000000000 00000 f \n";
  $this->write($table.'trailer << /Size '.($max+1).' /Root 1 0 R >>'."\nstartxref\n$xref\n%%EOF");

  if(!headers_sent()){
      // Drop stray output (e.g. notices) buffered before the PDF so the file is not corrupted.
      if (ob_get_length()) {
          @ob_clean();
      }
      header('Content-Type: application/pdf');
      $disp = strtolower($disposition) === 'inline' ? 'inline' : 'attachment';
      header('Content-Disposition: '.$disp.'; filename="'.$filename.'"');
      header('Content-Length: '.$this->pos);
  }
  rewind($this->out);fpassthru($this->out);fclose($this->out);$this->out=null;
  if(php_sapi_name()==='cli'){ return; }
  exit;
 }
}
