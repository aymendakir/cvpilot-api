<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListUsersRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['search' => 'nullable|string|max:120', ...$this->paginationRules()];
    }
}
