<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Application;
use App\Models\CareerReport;
use App\Models\CvDocument;
use App\Models\CvVersion;
use App\Models\InterviewSession;
use App\Models\JobWorkspace;
use Illuminate\Http\Request;

class LibraryController
{
    public function __invoke(Request $r)
    {
        $uid = $r->user()->id;
        $r->validate(['page' => 'nullable|integer|min:1', 'kind' => 'nullable|in:cv,interview,workspace,report,application,upload,cover_letter']);
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

        return ['counts' => $counts, 'items' => $q->latest()->paginate(12)];
    }
}
