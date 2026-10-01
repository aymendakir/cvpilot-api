<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class CoverLetterRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_text' => 'required|string|min:30|max:30000', 'job_description' => 'required|string|min:60|max:30000', 'name' => 'nullable|string|max:120', 'company' => 'nullable|string|max:160', 'position' => 'nullable|string|max:160', 'interest' => 'nullable|string|max:2000', 'language' => 'nullable|in:English,French,Spanish,Arabic', 'tone' => 'nullable|in:Professional,Confident,Warm'];
    }
}
