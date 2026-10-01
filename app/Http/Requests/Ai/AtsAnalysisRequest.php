<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class AtsAnalysisRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_text' => 'required|string|min:30|max:30000', 'job_description' => 'nullable|string|max:30000', 'report_format' => 'nullable|in:structured'];
    }
}
