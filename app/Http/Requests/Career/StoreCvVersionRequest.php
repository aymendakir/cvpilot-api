<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;

class StoreCvVersionRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['job_workspace_id' => 'nullable|integer', 'name' => 'required|string|max:180', 'content' => 'required|string|min:30|max:50000', 'source' => 'nullable|in:manual,ai,imported', 'builder_data' => 'nullable|array'];
    }
}
