<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Ai\AtsDocumentRequest;
use App\Services\AtsDocumentReview;

class AtsDocumentController
{
    public function analyze(AtsDocumentRequest $request, AtsDocumentReview $review): array
    {
        $input = $request->validated();

        return $review->analyze($input['cv_text'], $input['file_name'] ?? null);
    }
}
