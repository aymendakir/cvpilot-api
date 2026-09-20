<?php
namespace App\Services;
use Illuminate\Http\UploadedFile;
class DocumentExtractor{
 function extract(UploadedFile $file):string{
  $ext=strtolower($file->getClientOriginalExtension());
  if($ext==='txt')$text=file_get_contents($file->getRealPath());
  elseif($ext==='pdf')$text=(new \Smalot\PdfParser\Parser)->parseFile($file->getRealPath())->getText();
  elseif($ext==='docx'){$doc=\PhpOffice\PhpWord\IOFactory::load($file->getRealPath());$parts=[];foreach($doc->getSections() as $section)foreach($section->getElements() as $element)if(method_exists($element,'getText'))$parts[]=$element->getText();$text=implode("\n",$parts);}else abort(422,'Unsupported document type.');
  $text=trim(preg_replace('/[ \t]+/',' ',preg_replace('/\R{3,}/',"\n\n",$text??'')));abort_if(mb_strlen($text)<30,422,'No readable CV text found. Scanned PDFs require OCR before upload.');return mb_substr($text,0,100000);
 }
}
