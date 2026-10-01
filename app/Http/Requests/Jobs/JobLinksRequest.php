<?php

namespace App\Http\Requests\Jobs;

use App\Http\Requests\ApiFormRequest;

class JobLinksRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => 'required|string|max:120',
            'location' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:10',
        ];
    }
}
