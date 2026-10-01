<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;

/** A CV and a target job, the input of most career assistants. */
class DocumentsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_text' => 'required|string|min:30|max:30000', 'job_description' => 'required|string|min:60|max:30000'];
    }
}
