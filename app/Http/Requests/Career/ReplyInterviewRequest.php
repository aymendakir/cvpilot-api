<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Support\Facades\Gate;

class ReplyInterviewRequest extends ApiFormRequest
{
    /** The record must belong to the signed-in user before its input is validated (a foreign id stays a 404). */
    public function authorize(): bool
    {
        Gate::forUser($this->user())->authorize('update', $this->route('session'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['answer' => 'required|string|min:2|max:6000'];
    }
}
