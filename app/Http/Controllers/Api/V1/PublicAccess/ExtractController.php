<?php

namespace App\Http\Controllers\Api\V1\PublicAccess;

use App\Http\Requests\Cv\PublicExtractRequest;
use App\Services\Ats\Parsing\UnreadableDocument;
use App\Services\DocumentExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/public/cv/extract (API-A): reads a CV file without an account. The upload is read in place
 * and deleted; nothing is stored or logged. A file that cannot be read is `422` with an `errors.file` token.
 */
class ExtractController
{
    public function __invoke(PublicExtractRequest $request, DocumentExtractor $extractor): JsonResponse
    {
        try {
            $text = $extractor->extractTextAndDiscard($request->file('file'));
        } catch (UnreadableDocument $e) {
            throw ValidationException::withMessages(['file' => [$e->reason]]);
        }

        return response()->json(['text' => $text]);
    }
}
