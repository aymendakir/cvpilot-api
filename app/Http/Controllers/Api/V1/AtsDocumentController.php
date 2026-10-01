<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\AtsDocumentReview;
use Illuminate\Http\Request;

class AtsDocumentController
{
    public function analyze(Request $request, AtsDocumentReview $review): array
    {
        $input = $request->validate([
            'cv_text' => 'required|string|min:30|max:30000',
            'file_name' => 'nullable|string|max:180',
        ]);

        return $review->analyze($input['cv_text'], $input['file_name'] ?? null);
    }
}
