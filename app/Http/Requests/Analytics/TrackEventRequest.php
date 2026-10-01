<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiFormRequest;

class TrackEventRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'consent' => 'required|accepted', 'visitor_id' => 'required|string|min:16|max:100', 'session_id' => 'required|string|min:16|max:100',
            'path' => 'required|string|max:500', 'referrer' => 'nullable|url|max:1000', 'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255', 'utm_campaign' => 'nullable|string|max:255',
        ];
    }
}
