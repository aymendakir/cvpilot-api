<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Application;
use App\Models\CvVersion;
use App\Services\AdminReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController
{
    public function index(Request $r)
    {
        return Application::where('user_id', $r->user()->id)->latest()->paginate(20);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['cv_version_id' => 'nullable|integer', 'external_job_id' => 'nullable|string|max:255', 'title' => 'required|string|max:255', 'company' => 'required|string|max:255', 'url' => 'required|url:https|max:2000', 'job_description' => 'nullable|string|max:30000', 'status' => 'nullable|in:saved,prepared,applied,interview,rejected,offer', 'match_score' => 'nullable|integer|min:0|max:100', 'salary' => 'nullable|string|max:120', 'application_date' => 'nullable|date', 'reminder_at' => 'nullable|date', 'follow_up_at' => 'nullable|date', 'notes' => 'nullable|string|max:5000']);
        if (! empty($d['cv_version_id'])) {
            CvVersion::where('user_id', $r->user()->id)->findOrFail($d['cv_version_id']);
        }$d['user_id'] = $r->user()->id;
        if (($d['status'] ?? 'saved') === 'applied') {
            $d['applied_at'] = now();
            $d['application_date'] = $d['application_date'] ?? now()->toDateString();
        }$item = Application::updateOrCreate(['user_id' => $r->user()->id, 'url' => $d['url']], $d);
        AdminReview::record('application', $item);
        AuthController::audit($r, 'application_saved', $r->user()->id);

        return response()->json($item, $item->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $r, Application $application)
    {
        Gate::forUser($r->user())->authorize('view', $application);

        return $application;
    }

    public function update(Request $r, Application $application)
    {
        Gate::forUser($r->user())->authorize('update', $application);
        $d = $r->validate(['cv_version_id' => 'nullable|integer', 'status' => 'sometimes|in:saved,prepared,applied,interview,rejected,offer', 'salary' => 'nullable|string|max:120', 'application_date' => 'nullable|date', 'reminder_at' => 'nullable|date', 'follow_up_at' => 'nullable|date', 'notes' => 'nullable|string|max:5000']);
        if (! empty($d['cv_version_id'])) {
            CvVersion::where('user_id', $r->user()->id)->findOrFail($d['cv_version_id']);
        }if (($d['status'] ?? null) === 'applied' && ! $application->applied_at) {
            $d['applied_at'] = now();
        }$application->update($d);
        AdminReview::record('application', $application);
        AuthController::audit($r, 'application_updated', $r->user()->id);

        return $application;
    }

    public function destroy(Request $r, Application $application)
    {
        Gate::forUser($r->user())->authorize('delete', $application);
        AdminReview::forget('application', $application->id);
        $application->delete();

        return response()->noContent();
    }
}
