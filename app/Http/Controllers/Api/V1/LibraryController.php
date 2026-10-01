<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\LibraryRequest;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\CareerReportResource;
use App\Http\Resources\CvDocumentResource;
use App\Http\Resources\CvVersionResource;
use App\Http\Resources\InterviewSessionResource;
use App\Http\Resources\JobWorkspaceResource;
use App\Http\Resources\ModelResource;
use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\JobWorkspace;

class LibraryController
{
    /** @var array<string, class-string<ModelResource>> */
    private const RESOURCES = ['cv' => CvVersionResource::class, 'interview' => InterviewSessionResource::class, 'workspace' => JobWorkspaceResource::class, 'report' => CareerReportResource::class, 'application' => ApplicationResource::class, 'upload' => CvDocumentResource::class, 'cover_letter' => CareerReportResource::class];

    public function __invoke(LibraryRequest $r)
    {
        $uid = $r->user()->id;
        $models = ['cv' => CvVersion::class, 'interview' => InterviewSession::class, 'workspace' => JobWorkspace::class, 'report' => CareerReport::class, 'application' => Application::class, 'upload' => CvDocument::class, 'cover_letter' => CareerReport::class];
        $counts = [];
        foreach ($models as $key => $model) {
            $q = $model::where('user_id', $uid);
            if ($key === 'cover_letter') {
                $q->where('type', 'cover_letter');
            }$counts[$key] = $q->count();
        }$kind = $r->input('kind', 'cv');
        $q = $models[$kind]::where('user_id', $uid);
        if ($kind === 'cover_letter') {
            $q->where('type', 'cover_letter');
        }

        return ['counts' => $counts, 'items' => self::RESOURCES[$kind]::paginate($q->latest()->paginate($r->perPage(12)))];
    }
}
