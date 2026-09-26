<?php
namespace App\Http\Controllers;
use App\Models\CvDocument;
use App\Services\{DocumentExtractor,AtsScorer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
class CvController{
 function extract(Request $r,DocumentExtractor $extractor){
  $r->validate(['file'=>'required|file|mimes:pdf|max:10240']);
  try{$text=$extractor->extract($r->file('file'));}
  catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
  catch(\Throwable $e){return response()->json(['message'=>'This PDF could not be read. Upload an unlocked, text-based PDF or a DOCX.'],422);}
  return response()->json(['text'=>$text],200,['Cache-Control'=>'no-store, private']);
 }

 function index(Request $r){return CvDocument::where('user_id',$r->user()->id)->latest()->get();}
 function store(Request $r,DocumentExtractor $extractor){$r->validate(['file'=>'required|file|mimes:pdf,docx,txt|max:10240','is_primary'=>'nullable|boolean']);$file=$r->file('file');$text=$extractor->extract($file);$path=$file->store('cv/'.$r->user()->id,'local');return DB::transaction(function()use($r,$file,$text,$path){if($r->boolean('is_primary'))CvDocument::where('user_id',$r->user()->id)->update(['is_primary'=>false]);$doc=CvDocument::create(['user_id'=>$r->user()->id,'name'=>$file->getClientOriginalName(),'disk_path'=>$path,'mime'=>$file->getMimeType(),'size'=>$file->getSize(),'extracted_text'=>$text,'is_primary'=>$r->boolean('is_primary'),'expires_at'=>now()->addHours(48)]);AuthController::audit($r,'cv_uploaded',$r->user()->id);return response()->json($doc,201);});}
 function analyze(Request $r,CvDocument $cv,AtsScorer $scorer){abort_unless($cv->user_id===$r->user()->id,404);$d=$r->validate(['job_description'=>'required|string|min:60|max:30000']);$report=$scorer->score($cv->extracted_text,$d['job_description']);$id=DB::table('ats_reports')->insertGetId(['user_id'=>$r->user()->id,'cv_document_id'=>$cv->id,'score'=>$report['score'],'breakdown'=>json_encode($report['breakdown']),'matched_keywords'=>json_encode($report['matched_keywords']),'missing_keywords'=>json_encode($report['missing_keywords']),'suggestions'=>json_encode($report['suggestions']),'provider'=>'local','created_at'=>now(),'updated_at'=>now()]);AuthController::audit($r,'ats_analyzed',$r->user()->id);return ['id'=>$id]+$report;}
 function destroy(Request $r,CvDocument $cv){abort_unless($cv->user_id===$r->user()->id,404);Storage::disk('local')->delete($cv->disk_path);$cv->delete();return response()->noContent();}
}
