<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base class for every API input class. Authorization stays in routes
 * (middleware) and Policies, so requests only validate and normalize.
 * Failed validation is rendered by the shared error envelope.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Shared `page` / `per_page` rules for list endpoints (SPEC §5.2). */
    protected function paginationRules(): array
    {
        return ['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|between:1,50'];
    }

    /** Requested page size, or the endpoint's own default. */
    public function perPage(int $default): int
    {
        return (int) ($this->validated()['per_page'] ?? $default);
    }

    /** Trim the given string inputs (non-strings are left for the rules to reject). */
    protected function trimInputs(string ...$keys): void
    {
        $trimmed = [];
        foreach ($keys as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim($this->input($key));
            }
        }
        $this->merge($trimmed);
    }

    /** Lowercase and trim an email-like input when it is a string. */
    protected function normalizeEmail(string $key = 'email'): void
    {
        if (is_string($this->input($key))) {
            $this->merge([$key => strtolower(trim($this->input($key)))]);
        }
    }
}
