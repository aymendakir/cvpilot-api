<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class TrafficReportRequest extends ApiFormRequest
{
    public const DAYS = [7, 30, 90];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['days' => 'nullable|integer|in:'.implode(',', self::DAYS)];
    }
}
