<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ReorderIntegrationsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:integrations,id',
            'items.*.priority' => 'required|integer|min:1|max:999',
        ];
    }
}
