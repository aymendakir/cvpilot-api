<?php
namespace App\Http\Controllers;
use App\Models\CvDocument;
use App\Services\AiGateway;
use Illuminate\Http\Request;
class AiController{
 function chat(Request $r,AiGateway $ai){$d=$r->validate(['message'=>'required|string|max:6000','provider'=>'nullable|string|max:30','context'=>'nullable|string|max:12000']);$prompt="User question:\n{$d['message']}".(!empty($d['context'])?"\n\nContext:\n{$d['context']}":'');return $ai->chat($prompt,'assistant',$r->user()->id,$d['provider']??null);}
 function improveCv(Request $r,AiGateway $ai){$d=$r->validate(['cv_document_id'=>'required|integer','job_description'=>'required|string|min:60|max:30000']);$cv=CvDocument::where('user_id',$r->user()->id)->where('expires_at','>',now())->findOrFail($d['cv_document_id']);$prompt="Analyze this CV against the job. Return concise JSON with score, missing_keywords, strengths, and line_suggestions. Never invent experience.\n\nCV:\n{$cv->extracted_text}\n\nJOB:\n{$d['job_description']}";return $this->saved($r,$ai,$prompt,'ats',$d);}
 function atsAnalysis(Request $r,AiGateway $ai){
  $d=$r->validate(['cv_text'=>'required|string|min:30|max:30000','job_description'=>'required|string|min:60|max:30000']);
  $prompt="You are an expert ATS resume reviewer. Compare the candidate CV with the job description. Never invent experience, skills, dates, education, numbers, or achievements. Write a concise, practical report in the same language as the job description. Use exactly these headings:\n\nOVERALL ASSESSMENT\nExplain the fit in 2-3 sentences.\n\nSKILLS MATCHED\nList proven matching skills.\n\nSKILLS MISSING OR WEAK\nList important job skills that are absent or weak. Tell the candidate to add them only if true.\n\nREQUIREMENTS YOU MEET\nMap job requirements to exact CV evidence.\n\nREQUIREMENTS TO VERIFY OR BUILD\nList requirements without clear CV evidence.\n\nLINES TO CHANGE\nFor each important change, copy the exact original CV line, then write a stronger replacement and a short reason. Preserve the original facts. If a metric would help but is unknown, use [add a truthful number] instead of inventing it.\n\nLIKELY INTERVIEW QUESTIONS\nGive 5 questions based on the job and the candidate's CV.\n\nPRIORITY ACTIONS\nGive the 3 most valuable next actions.\n\nCV:\n{$d['cv_text']}\n\nJOB DESCRIPTION:\n{$d['job_description']}";
  return $this->saved($r,$ai,$prompt,'ats',$d);
 }
 function coverLetter(Request $r,AiGateway $ai){
  $d=$r->validate(['cv_text'=>'required|string|min:30|max:30000','job_description'=>'required|string|min:60|max:30000','name'=>'nullable|string|max:120','company'=>'nullable|string|max:160','position'=>'nullable|string|max:160','interest'=>'nullable|string|max:2000','language'=>'nullable|in:English,French,Spanish,Arabic','tone'=>'nullable|in:Professional,Confident,Warm']);
  $name=trim($d['name']??'')?:'[Your name]';$company=trim($d['company']??'')?:'the company';$position=trim($d['position']??'')?:'the advertised position';$language=$d['language']??'English';$tone=$d['tone']??'Professional';$interest=trim($d['interest']??'');
  $prompt="Write a tailored cover letter in {$language} with a {$tone} tone. Address the {$position} role at {$company}. Use only facts explicitly present in the CV or the candidate's interest note. Never invent achievements, years, metrics, employers, degrees, or skills. Connect the strongest relevant CV evidence to the job requirements. Keep it between 250 and 400 words, natural and specific, with a greeting, 3-4 short paragraphs, and a closing signed {$name}. Return only the finished letter with no markdown, commentary, or placeholders except the supplied name.\n\nCANDIDATE INTEREST NOTE:\n{$interest}\n\nCV:\n{$d['cv_text']}\n\nJOB DESCRIPTION:\n{$d['job_description']}";
  return $this->saved($r,$ai,$prompt,'cover_letter',$d);
 }
 private function saved(Request $r,AiGateway $ai,string $prompt,string $type,array $input):array{
  $result=$ai->chat($prompt,$type,$r->user()->id);$report=\App\Models\CareerReport::create(['user_id'=>$r->user()->id,'type'=>$type,'input'=>$input,'output'=>$result['answer']]);
  \App\Services\AdminReview::record('report',$report);AuthController::audit($r,$type.'_generated',$r->user()->id);return $result+['report_id'=>$report->id];
 }
}
