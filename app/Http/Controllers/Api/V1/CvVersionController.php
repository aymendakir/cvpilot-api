<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CvVersion;
use App\Models\JobWorkspace;
use App\Services\AdminReview;
use Illuminate\Http\Request;

class CvVersionController
{
    public function index(Request $r)
    {
        return CvVersion::where('user_id', $r->user()->id)->latest()->limit(100)->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate(['job_workspace_id' => 'nullable|integer', 'name' => 'required|string|max:180', 'content' => 'required|string|min:30|max:50000', 'source' => 'nullable|in:manual,ai,imported', 'builder_data' => 'nullable|array']);
        if (! empty($d['job_workspace_id'])) {
            JobWorkspace::where('user_id', $r->user()->id)->findOrFail($d['job_workspace_id']);
        }$d['user_id'] = $r->user()->id;
        $item = CvVersion::create($d);
        AdminReview::record('cv', $item);
        AuthController::audit($r, 'cv_saved', $r->user()->id);

        return response()->json($item, 201);
    }

    public function show(Request $r, CvVersion $version)
    {
        abort_unless($version->user_id === $r->user()->id, 404);

        return $version;
    }

    public function update(Request $r, CvVersion $version)
    {
        abort_unless($version->user_id === $r->user()->id, 404);
        $d = $r->validate(['name' => 'required|string|max:180', 'content' => 'required|string|min:30|max:50000', 'builder_data' => 'nullable|array']);
        $version->update($d);
        AdminReview::record('cv', $version);

        return $version;
    }

    public function destroy(Request $r, CvVersion $version)
    {
        abort_unless($version->user_id === $r->user()->id, 404);
        AdminReview::forget('cv', $version->id);
        $version->delete();

        return response()->noContent();
    }
}
