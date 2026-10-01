<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;

class StoreJobWorkspaceRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['title' => 'required|string|max:180', 'company' => 'nullable|string|max:180', 'job_url' => 'nullable|url:https|max:2000', 'job_description' => 'required|string|min:60|max:30000', 'cv_text' => 'required|string|min:30|max:30000'];
    }
}
