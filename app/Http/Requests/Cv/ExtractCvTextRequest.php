<?php

namespace App\Http\Requests\Cv;

use App\Http\Requests\ApiFormRequest;
use App\Services\DocumentExtractor;

class ExtractCvTextRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => 'required|file|max:'.DocumentExtractor::MAX_KILOBYTES];
    }
}
