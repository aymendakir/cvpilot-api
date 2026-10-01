<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListAuditEventsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['event' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1'];
    }
}
