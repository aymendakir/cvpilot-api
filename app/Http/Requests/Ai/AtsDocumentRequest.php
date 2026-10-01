<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class AtsDocumentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cv_text' => 'required|string|min:30|max:30000',
            'file_name' => 'nullable|string|max:180',
        ];
    }
}
