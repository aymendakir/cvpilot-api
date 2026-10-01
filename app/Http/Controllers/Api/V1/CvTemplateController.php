<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Admin\CvTemplateRequest;
use App\Http\Resources\CvTemplateResource;
use App\Models\CvTemplate;
use Illuminate\Http\Request;

class CvTemplateController
{
    public function published()
    {
        return CvTemplateResource::collection(CvTemplate::where('published', true)->latest()->get());
    }

    public function index()
    {
        return CvTemplateResource::collection(CvTemplate::latest()->get());
    }

    public function store(CvTemplateRequest $r)
    {
        $template = CvTemplate::create($r->validated());
        AuthController::audit($r, 'template_created', $r->user()->id);

        return CvTemplateResource::make($template)->response()->setStatusCode(201);
    }

    public function update(CvTemplateRequest $r, CvTemplate $template)
    {
        $template->update($r->validated());
        AuthController::audit($r, 'template_updated', $r->user()->id);

        return CvTemplateResource::make($template);
    }

    public function destroy(Request $r, CvTemplate $template)
    {
        $template->delete();
        AuthController::audit($r, 'template_deleted', $r->user()->id);

        return response()->noContent();
    }
}
