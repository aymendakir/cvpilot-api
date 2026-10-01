<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SuspendUserRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['suspended' => 'required|boolean'];
    }
}
