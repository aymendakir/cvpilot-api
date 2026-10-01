<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListContactMessagesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => 'nullable|in:new,read,closed', 'page' => 'nullable|integer|min:1'];
    }
}
