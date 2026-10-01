<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class ImproveCvRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_document_id' => 'required|integer', 'job_description' => 'required|string|min:60|max:30000'];
    }
}
