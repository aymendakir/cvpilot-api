<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Career\StoreCvVersionRequest;
use App\Http\Requests\Career\UpdateCvVersionRequest;
use App\Http\Resources\CvVersionResource;
use App\Models\CvVersion;
use App\Models\JobWorkspace;
use App\Services\AdminReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CvVersionController
{
    public function index(Request $r)
    {
        return CvVersionResource::collection(CvVersion::where('user_id', $r->user()->id)->latest()->limit(100)->get());
    }

    public function store(StoreCvVersionRequest $r)
    {
        $d = $r->validated();
        if (! empty($d['job_workspace_id'])) {
            JobWorkspace::where('user_id', $r->user()->id)->findOrFail($d['job_workspace_id']);
        }$d['user_id'] = $r->user()->id;
        $item = CvVersion::create($d);
        AdminReview::record('cv', $item);
        AuthController::audit($r, 'cv_saved', $r->user()->id);

        return CvVersionResource::make($item)->response()->setStatusCode(201);
    }

    public function show(Request $r, CvVersion $version)
    {
        Gate::forUser($r->user())->authorize('view', $version);

        return CvVersionResource::make($version);
    }

    public function update(UpdateCvVersionRequest $r, CvVersion $version)
    {
        $d = $r->validated();
        $version->update($d);
        AdminReview::record('cv', $version);

        return CvVersionResource::make($version);
    }

    public function destroy(Request $r, CvVersion $version)
    {
        Gate::forUser($r->user())->authorize('delete', $version);
        AdminReview::forget('cv', $version->id);
        $version->delete();

        return response()->noContent();
    }
}
