<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\Application;

class ListApplicationsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->paginationRules(), 'search' => 'nullable|string|max:120', 'status' => 'nullable|in:'.implode(',', Application::STATUSES)];
    }
}
