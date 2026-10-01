<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateContactMessageRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => 'required|in:new,read,closed'];
    }
}
