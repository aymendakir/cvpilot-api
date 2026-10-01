<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/** An admin creates an account. Making an admin needs `confirm_admin: true` on top of `role: admin`. */
class CreateUserRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimInputs('name');
        $this->normalizeEmail();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')],
            'password' => 'required|string|min:12|max:128|confirmed',
            'role' => ['required', Rule::in(['user', 'admin'])],
            'verified' => 'nullable|boolean',
            'confirm_admin' => 'accepted_if:role,admin',
        ];
    }
}
