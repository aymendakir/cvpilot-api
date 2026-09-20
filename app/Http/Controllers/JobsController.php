<?php
namespace App\Http\Controllers;
use App\Models\CvDocument;
use App\Services\{JobSearchService,AtsScorer};
use Illuminate\Http\Request;
class JobsController{
 function search(Request $r,JobSearchService $jobs,AtsScorer $ats){$d=$r->validate(['q'=>'required|string|max:120','country'=>'required|string|size:2','country_name'=>'nullable|string|max:120','city'=>'nullable|string|max:120','date'=>'nullable|in:today,3days,week,month,all','page'=>'nullable|integer|min:1|max:10','provider'=>'nullable|in:jsearch,adzuna,jooble,arbeitnow','cv_document_id'=>'nullable|integer']);$result=$jobs->search($d);$cv=null;if(!empty($d['cv_document_id']))$cv=CvDocument::where('user_id',$r->user()->id)->findOrFail($d['cv_document_id'])->extracted_text;$result['data']=collect($result['data'])->take(100)->map(function($job)use($cv,$ats){$job['match']=$cv?$ats->score($cv,$job['title'].' '.$job['description']):null;return $job;})->values();$result['page']=$d['page']??1;$result['count']=count($result['data']);return $result;}
 function links(Request $r){$d=$r->validate(['q'=>'required|string|max:120','location'=>'nullable|string|max:180']);$q=urlencode($d['q']);$l=urlencode($d['location']??'');return ['linkedin'=>"https://www.linkedin.com/jobs/search/?keywords=$q&location=$l",'indeed'=>"https://www.indeed.com/jobs?q=$q&l=$l",'google'=>"https://www.google.com/search?q=$q+jobs+$l"];}
}
