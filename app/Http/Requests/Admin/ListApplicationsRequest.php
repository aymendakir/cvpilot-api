<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListApplicationsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:120', 'status' => 'nullable|string|max:40'];
    }
}
