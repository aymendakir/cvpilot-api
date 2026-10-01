<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;

class LibraryRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => 'nullable|integer|min:1', 'kind' => 'nullable|in:cv,interview,workspace,report,application,upload,cover_letter'];
    }
}
