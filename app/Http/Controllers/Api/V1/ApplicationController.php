<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Applications\ListApplicationsRequest;
use App\Http\Requests\Applications\StoreApplicationRequest;
use App\Http\Requests\Applications\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\CvVersion;
use App\Services\AdminReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController
{
    public function index(ListApplicationsRequest $r)
    {
        return ApplicationResource::paginate(Application::where('user_id', $r->user()->id)->latest()->paginate($r->perPage(20)));
    }

    public function store(StoreApplicationRequest $r)
    {
        $d = $r->validated();
        if (! empty($d['cv_version_id'])) {
            CvVersion::where('user_id', $r->user()->id)->findOrFail($d['cv_version_id']);
        }$d['user_id'] = $r->user()->id;
        if (($d['status'] ?? 'saved') === 'applied') {
            $d['applied_at'] = now();
            $d['application_date'] = $d['application_date'] ?? now()->toDateString();
        }$item = Application::updateOrCreate(['user_id' => $r->user()->id, 'url' => $d['url']], $d);
        AdminReview::record('application', $item);
        AuthController::audit($r, 'application_saved', $r->user()->id);

        return ApplicationResource::make($item)->response()->setStatusCode($item->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $r, Application $application)
    {
        Gate::forUser($r->user())->authorize('view', $application);

        return ApplicationResource::make($application);
    }

    public function update(UpdateApplicationRequest $r, Application $application)
    {
        $d = $r->validated();
        if (! empty($d['cv_version_id'])) {
            CvVersion::where('user_id', $r->user()->id)->findOrFail($d['cv_version_id']);
        }if (($d['status'] ?? null) === 'applied' && ! $application->applied_at) {
            $d['applied_at'] = now();
        }$application->update($d);
        AdminReview::record('application', $application);
        AuthController::audit($r, 'application_updated', $r->user()->id);

        return ApplicationResource::make($application);
    }

    public function destroy(Request $r, Application $application)
    {
        Gate::forUser($r->user())->authorize('delete', $application);
        AdminReview::forget('application', $application->id);
        $application->delete();

        return response()->noContent();
    }
}
