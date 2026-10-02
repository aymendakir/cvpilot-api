<?php

namespace App\Http\Requests\Cv;

use App\Http\Requests\ApiFormRequest;
use App\Services\DocumentExtractor;

/** POST public/cv/extract (API-A): like cv-documents/extract, with `errors.file` tokens (D4). */
class PublicExtractRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.DocumentExtractor::MAX_KILOBYTES]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'missing',
            'file.file' => 'upload_failed',
            'file.uploaded' => 'upload_failed',
            'file.max' => 'too_large',
        ];
    }
}
