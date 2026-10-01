<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\StoreJobWorkspaceRequest;
use App\Http\Resources\JobWorkspaceResource;
use App\Models\JobWorkspace;
use App\Services\AdminReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JobWorkspaceController
{
    public function index(Request $r)
    {
        return JobWorkspaceResource::collection(JobWorkspace::where('user_id', $r->user()->id)->latest()->limit(100)->get());
    }

    public function store(StoreJobWorkspaceRequest $r)
    {
        $d = $r->validated();
        $d['user_id'] = $r->user()->id;
        $item = JobWorkspace::create($d);
        AdminReview::record('workspace', $item);
        AuthController::audit($r, 'workspace_saved', $r->user()->id);

        return JobWorkspaceResource::make($item)->response()->setStatusCode(201);
    }

    public function show(Request $r, JobWorkspace $workspace)
    {
        Gate::forUser($r->user())->authorize('view', $workspace);

        return JobWorkspaceResource::make($workspace);
    }
}
