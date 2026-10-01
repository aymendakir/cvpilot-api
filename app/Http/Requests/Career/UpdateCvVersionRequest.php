<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateCvVersionRequest extends ApiFormRequest
{
    /** The record must belong to the signed-in user before its input is validated (a foreign id stays a 404). */
    public function authorize(): bool
    {
        Gate::forUser($this->user())->authorize('update', $this->route('version'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => 'required|string|max:180', 'content' => 'required|string|min:30|max:50000', 'builder_data' => 'nullable|array'];
    }
}
