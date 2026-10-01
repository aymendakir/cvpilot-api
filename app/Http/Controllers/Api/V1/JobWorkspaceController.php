<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JobWorkspace;
use App\Services\AdminReview;
use Illuminate\Http\Request;

class JobWorkspaceController
{
    public function index(Request $r)
    {
        return JobWorkspace::where('user_id', $r->user()->id)->latest()->limit(100)->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:180', 'company' => 'nullable|string|max:180', 'job_url' => 'nullable|url:https|max:2000', 'job_description' => 'required|string|min:60|max:30000', 'cv_text' => 'required|string|min:30|max:30000']);
        $d['user_id'] = $r->user()->id;
        $item = JobWorkspace::create($d);
        AdminReview::record('workspace', $item);
        AuthController::audit($r, 'workspace_saved', $r->user()->id);

        return response()->json($item, 201);
    }

    public function show(Request $r, JobWorkspace $workspace)
    {
        abort_unless($workspace->user_id === $r->user()->id, 404);

        return $workspace;
    }
}
