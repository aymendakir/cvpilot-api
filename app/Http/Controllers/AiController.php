<?php
namespace App\Http\Controllers;
use App\Models\CvDocument;
use App\Services\AiGateway;
use Illuminate\Http\Request;
class AiController{
 function chat(Request $r,AiGateway $ai){$d=$r->validate(['message'=>'required|string|max:6000','provider'=>'nullable|string|max:30','context'=>'nullable|string|max:12000']);$prompt="User question:\n{$d['message']}".(!empty($d['context'])?"\n\nContext:\n{$d['context']}":'');return $ai->chat($prompt,'assistant',$r->user()->id,$d['provider']??null);}
 function improveCv(Request $r,AiGateway $ai){$d=$r->validate(['cv_document_id'=>'required|integer','job_description'=>'required|string|min:60|max:30000']);$cv=CvDocument::where('user_id',$r->user()->id)->findOrFail($d['cv_document_id']);$prompt="Analyze this CV against the job. Return concise JSON with score, missing_keywords, strengths, and line_suggestions. Never invent experience.\n\nCV:\n{$cv->extracted_text}\n\nJOB:\n{$d['job_description']}";return $ai->chat($prompt,'ats',$r->user()->id);}
 function atsAnalysis(Request $r,AiGateway $ai){
  $d=$r->validate(['cv_text'=>'required|string|min:30|max:30000','job_description'=>'nullable|string|max:30000']);
  $hasJob = !empty(trim($d['job_description'] ?? ''));
  if ($hasJob) {
    $prompt="You are an expert cross-industry ATS resume reviewer. Compare the candidate CV with the job description. Focus strictly on the most important, high-impact items. Never invent experience or facts. Use these headings:\n\nOVERALL ASSESSMENT\n2-3 concise sentences on job match.\n\nSKILLS MATCHED\nKey proven matching skills.\n\nSKILLS MISSING OR WEAK\nTop 3-5 missing job requirements.\n\nCRITICAL LINES TO CHANGE\nMax 3-4 high-impact suggestions only. Provide: Original line, Stronger replacement (with action verb or truthful metric), and Brief reason.\n\nPRIORITY NEXT STEPS\nThe 2-3 most valuable actions.\n\nCV:\n{$d['cv_text']}\n\nJOB DESCRIPTION:\n{$d['job_description']}";
  } else {
    $prompt="You are an expert cross-industry ATS resume reviewer. Audit this CV across any profession (Tech, Marketing, Sales, Finance, HR, Operations, Design, Support, Healthcare, etc.). Focus strictly on the most important, high-impact improvements. Never invent experience. Use these headings:\n\nOVERALL ASSESSMENT\n2-3 sentences evaluating ATS readability and resume strength.\n\nCORE STRENGTHS\nProven competencies and achievements detected.\n\nRECOMMENDED INDUSTRY KEYWORDS\nKey in-demand keywords that would elevate this profile.\n\nCRITICAL LINES TO CHANGE\nMax 3-4 high-impact suggestions only. Provide: Original line, Stronger replacement (with action verb or truthful metric), and Brief reason.\n\nPRIORITY NEXT STEPS\nThe 2-3 most valuable actions.\n\nCV:\n{$d['cv_text']}";
  }
  return $ai->chat($prompt,'ats',$r->user()->id);
 }
 function coverLetter(Request $r,AiGateway $ai){
  $d=$r->validate(['cv_text'=>'required|string|min:30|max:30000','job_description'=>'required|string|min:60|max:30000','name'=>'nullable|string|max:120','company'=>'nullable|string|max:160','position'=>'nullable|string|max:160','interest'=>'nullable|string|max:2000','language'=>'nullable|in:English,French,Spanish,Arabic','tone'=>'nullable|in:Professional,Confident,Warm']);
  $name=trim($d['name']??'')?:'[Your name]';$company=trim($d['company']??'')?:'the company';$position=trim($d['position']??'')?:'the advertised position';$language=$d['language']??'English';$tone=$d['tone']??'Professional';$interest=trim($d['interest']??'');
  $prompt="Write a tailored cover letter in {$language} with a {$tone} tone. Address the {$position} role at {$company}. Use only facts explicitly present in the CV or the candidate's interest note. Never invent achievements, years, metrics, employers, degrees, or skills. Connect the strongest relevant CV evidence to the job requirements. Keep it between 250 and 400 words, natural and specific, with a greeting, 3-4 short paragraphs, and a closing signed {$name}. Return only the finished letter with no markdown, commentary, or placeholders except the supplied name.\n\nCANDIDATE INTEREST NOTE:\n{$interest}\n\nCV:\n{$d['cv_text']}\n\nJOB DESCRIPTION:\n{$d['job_description']}";
  $res=$ai->chat($prompt,'cover_letter',$r->user()->id);
  $rep=\App\Models\CareerReport::create(['user_id'=>$r->user()->id,'type'=>'cover_letter','input'=>['title'=>$position,'company'=>$company,'name'=>$name,'language'=>$language,'tone'=>$tone],'output'=>$res['answer']]);
  \App\Services\AdminReview::record('report',$rep);
  AuthController::audit($r,'cover_letter_generated',$r->user()->id);
  return $res+['report_id'=>$rep->id];
 }
}
