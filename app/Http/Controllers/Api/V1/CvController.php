<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Cv\ExtractCvTextRequest;
use App\Http\Requests\Cv\StoreCvDocumentRequest;
use App\Http\Resources\CvDocumentResource;
use App\Models\CvDocument;
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
        // Read the metadata first: the temp file is deleted as soon as the text is extracted.
        $name = $file->getClientOriginalName();
        $mime = $file->getMimeType();
        $size = $file->getSize();
        $text = $extractor->extractAndDiscard($file);

        return DB::transaction(function () use ($r, $name, $mime, $size, $text) {
            if ($r->boolean('is_primary')) {
                CvDocument::where('user_id', $r->user()->id)->update(['is_primary' => false]);
            }$doc = CvDocument::create(['user_id' => $r->user()->id, 'name' => $name, 'disk_path' => null, 'mime' => $mime, 'size' => $size, 'extracted_text' => $text, 'is_primary' => $r->boolean('is_primary'), 'expires_at' => now()->addHours(48)]);
            AuthController::audit($r, 'cv_uploaded', $r->user()->id);

            return CvDocumentResource::make($doc)->response()->setStatusCode(201);
        });
    }

    /** Text extraction only: nothing is stored and no CvDocument is created. */
    public function extract(ExtractCvTextRequest $r, DocumentExtractor $extractor)
    {

        return response()->json(['text' => $extractor->extractAndDiscard($r->file('file'))]);
    }

    public function destroy(Request $r, CvDocument $cv)
    {
        Gate::forUser($r->user())->authorize('delete', $cv);
        // Rows from before S6 may still point to a stored original; new ones never do.
        if ($cv->disk_path) {
            Storage::disk('local')->delete($cv->disk_path);
        }
        $cv->delete();

        return response()->noContent();
    }
}
