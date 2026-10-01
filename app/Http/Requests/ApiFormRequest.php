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
