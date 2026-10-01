<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SendWarningRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['subject' => 'required|string|max:180', 'message' => 'required|string|min:5|max:5000', 'severity' => 'nullable|string|in:notice,warning,urgent'];
    }
}
