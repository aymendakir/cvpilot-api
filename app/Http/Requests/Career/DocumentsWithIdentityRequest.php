<?php

namespace App\Http\Requests\Career;

/** CV, job and the role/company they are for. */
class DocumentsWithIdentityRequest extends DocumentsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['title' => 'required|string|max:180', 'company' => 'nullable|string|max:180'];
    }
}
