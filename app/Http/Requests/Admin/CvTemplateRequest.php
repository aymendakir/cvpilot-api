<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/** Create and update share the same template shape. */
class CvTemplateRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'description' => 'nullable|string|max:255', 'published' => 'required|boolean',
            'design' => 'required|array:layout,accent,font,spacing', 'design.layout' => 'required|in:classic,modern,executive,minimal,compact',
            'design.accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'design.font' => 'required|in:sans,serif',
            'design.spacing' => 'required|in:comfortable,compact', 'sample' => 'required|array:name,role,email,phone,location,summary,skills,experience,education',
            'sample.name' => 'required|string|max:120', 'sample.role' => 'nullable|string|max:180', 'sample.email' => 'nullable|email|max:254',
            'sample.phone' => 'nullable|string|max:40', 'sample.location' => 'nullable|string|max:180', 'sample.summary' => 'nullable|string|max:5000',
            'sample.skills' => 'nullable|string|max:5000', 'sample.experience' => 'present|array|max:30', 'sample.education' => 'present|array|max:30',
            'sample.experience.*' => 'array:title,place,dates,details', 'sample.education.*' => 'array:title,place,dates,details',
            'sample.experience.*.*' => 'nullable|string|max:5000', 'sample.education.*.*' => 'nullable|string|max:5000',
        ];
    }
}
