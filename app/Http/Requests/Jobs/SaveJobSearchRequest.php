<?php

namespace App\Http\Requests\Jobs;

use App\Http\Requests\ApiFormRequest;

class SaveJobSearchRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'query' => 'required|string|max:120',
            'country' => 'required|string|max:10',
            'country_name' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'experience' => 'nullable|string|max:30',
            'work_mode' => 'nullable|in:any,remote,hybrid,onsite',
            'filters' => 'nullable|array',
            'alerts_enabled' => 'nullable|boolean',
            'alert_frequency' => 'nullable|in:daily,weekly',
        ];
    }
}
