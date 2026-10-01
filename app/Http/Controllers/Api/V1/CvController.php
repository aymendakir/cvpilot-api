<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Cv\ExtractCvTextRequest;
use App\Http\Requests\Cv\StoreCvDocumentRequest;
use App\Http\Resources\CvDocumentResource;
use App\Models\CvDocument;
use App\Services\AtsScorer;
use App\Services\DocumentExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CvController
{
    public function index(Request $r)
    {
        return CvDocumentResource::collection(CvDocument::where('user_id', $r->user()->id)->latest()->get());
    }

    public function store(StoreCvDocumentRequest $r, DocumentExtractor $extractor)
    {
        $file = $r->file('file');
        $text = $extractor->extract($file);
        $path = $file->store('cv/'.$r->user()->id, 'local');

        return DB::transaction(function () use ($r, $file, $text, $path) {
            if ($r->boolean('is_primary')) {
                CvDocument::where('user_id', $r->user()->id)->update(['is_primary' => false]);
            }$doc = CvDocument::create(['user_id' => $r->user()->id, 'name' => $file->getClientOriginalName(), 'disk_path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'extracted_text' => $text, 'is_primary' => $r->boolean('is_primary'), 'expires_at' => now()->addHours(48)]);
            AuthController::audit($r, 'cv_uploaded', $r->user()->id);

            return CvDocumentResource::make($doc)->response()->setStatusCode(201);
        });
    }

    /** Text extraction only: nothing is stored and no CvDocument is created. */
    public function extract(ExtractCvTextRequest $r, DocumentExtractor $extractor)
    {

        return response()->json(['text' => $extractor->extract($r->file('file'))]);
    }

    public function analyze(Request $r, CvDocument $cv, AtsScorer $scorer)
    {
        Gate::forUser($r->user())->authorize('view', $cv);
        $d = $r->validate(['job_description' => 'required|string|min:60|max:30000']);
        $report = $scorer->score($cv->extracted_text, $d['job_description']);
        $id = DB::table('ats_reports')->insertGetId(['user_id' => $r->user()->id, 'cv_document_id' => $cv->id, 'score' => $report['score'], 'breakdown' => json_encode($report['breakdown']), 'matched_keywords' => json_encode($report['matched_keywords']), 'missing_keywords' => json_encode($report['missing_keywords']), 'suggestions' => json_encode($report['suggestions']), 'provider' => 'local', 'created_at' => now(), 'updated_at' => now()]);
        AuthController::audit($r, 'ats_analyzed', $r->user()->id);

        return ['id' => $id] + $report;
    }

    public function destroy(Request $r, CvDocument $cv)
    {
        Gate::forUser($r->user())->authorize('delete', $cv);
        Storage::disk('local')->delete($cv->disk_path);
        $cv->delete();

        return response()->noContent();
    }
}
