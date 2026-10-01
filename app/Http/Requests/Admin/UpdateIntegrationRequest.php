<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateIntegrationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'secret' => 'nullable|string|max:1000',
            'model' => 'nullable|string|max:120',
            'settings' => 'nullable|array',
            'enabled' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:1|max:999',
        ];
    }
}
