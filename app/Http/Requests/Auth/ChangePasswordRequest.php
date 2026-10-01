<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ChangePasswordRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['current_password' => 'required', 'password' => 'required|string|min:12|max:128|confirmed'];
    }
}
