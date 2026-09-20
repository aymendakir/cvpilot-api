<?php
namespace App\Http\Controllers;
use App\Models\CvTemplate;
use Illuminate\Http\Request;

class CvTemplateController {
    public function published() { return CvTemplate::where('published',true)->latest()->get(); }
    public function index() { return CvTemplate::latest()->get(); }
    public function store(Request $r) { $template=CvTemplate::create($this->data($r)); AuthController::audit($r,'template_created',$r->user()->id); return response()->json($template,201); }
    public function update(Request $r, CvTemplate $template) { $template->update($this->data($r)); AuthController::audit($r,'template_updated',$r->user()->id); return $template; }
    public function destroy(Request $r, CvTemplate $template) { $template->delete(); AuthController::audit($r,'template_deleted',$r->user()->id); return response()->noContent(); }
    private function data(Request $r): array {
        return $r->validate([
            'name'=>'required|string|max:120','description'=>'nullable|string|max:255','published'=>'required|boolean',
            'design'=>'required|array:layout,accent,font,spacing','design.layout'=>'required|in:classic,modern,executive,minimal,compact',
            'design.accent'=>'required|regex:/^#[0-9a-fA-F]{6}$/','design.font'=>'required|in:sans,serif',
            'design.spacing'=>'required|in:comfortable,compact','sample'=>'required|array:name,role,email,phone,location,summary,skills,experience,education',
            'sample.name'=>'required|string|max:120','sample.role'=>'nullable|string|max:180','sample.email'=>'nullable|email|max:254',
            'sample.phone'=>'nullable|string|max:40','sample.location'=>'nullable|string|max:180','sample.summary'=>'nullable|string|max:5000',
            'sample.skills'=>'nullable|string|max:5000','sample.experience'=>'present|array|max:30','sample.education'=>'present|array|max:30',
            'sample.experience.*'=>'array:title,place,dates,details','sample.education.*'=>'array:title,place,dates,details',
            'sample.experience.*.*'=>'nullable|string|max:5000','sample.education.*.*'=>'nullable|string|max:5000',
        ]);
    }
}
