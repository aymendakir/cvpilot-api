<?php

namespace App\Http\Requests\Applications;

use App\Http\Requests\ApiFormRequest;
use App\Models\Application;
use Illuminate\Support\Facades\Gate;

class UpdateApplicationRequest extends ApiFormRequest
{
    /** The record must belong to the signed-in user before its input is validated (a foreign id stays a 404). */
    public function authorize(): bool
    {
        Gate::forUser($this->user())->authorize('update', $this->route('application'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cv_version_id' => 'nullable|integer', 'status' => 'sometimes|in:'.implode(',', Application::STATUSES).'', 'salary' => 'nullable|string|max:120', 'application_date' => 'nullable|date', 'reminder_at' => 'nullable|date', 'follow_up_at' => 'nullable|date', 'notes' => 'nullable|string|max:5000'];
    }
}
