<?php

namespace App\Http\Requests\Ats;

use App\Http\Requests\ApiFormRequest;

/**
 * POST ats/analyses (SPEC-ats.md §5.1). Exactly one of `file` / `cv_text`. Whether the file is really
 * a PDF or DOCX is decided from its content by the analyzer, after validation.
 *
 * `errors.file` carries stable reason tokens, not sentences (S4 decision 29): the client maps them to
 * EN/FR text. The other fields keep Laravel's messages.
 */
class StoreAtsAnalysisRequest extends ApiFormRequest
{
    public const MAX_KILOBYTES = 15 * 1024;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required_without:cv_text', 'prohibits:cv_text', 'file', 'max:'.self::MAX_KILOBYTES],
            'cv_text' => ['required_without:file', 'string', 'min:30', 'max:30000'],
            'job_description' => ['nullable', 'string', 'min:60', 'max:30000'],
            'locale' => ['nullable', 'string', 'in:en,fr'],
            'include_text' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required_without' => 'missing',
            'file.prohibits' => 'both_given',
            'file.file' => 'upload_failed',
            'file.uploaded' => 'upload_failed',
            'file.max' => 'too_large',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimInputs('cv_text', 'job_description', 'locale');
    }
}
