<?php

namespace App\Http\Requests\Career;

use App\Http\Requests\ApiFormRequest;
use App\Services\OutputLanguage;

/** The career diagnostic reads the user's own applications; the only input is the language of the answer. */
class DiagnosticRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['language' => OutputLanguage::RULE];
    }
}
