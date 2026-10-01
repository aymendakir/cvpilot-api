<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\SiteSetting;
use Illuminate\Validation\Rule;

class SaveSiteSettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'brand_name' => 'required|string|max:80', 'site_url' => 'required|url:http,https|max:253',
            'default_title' => 'required|string|max:100', 'default_description' => 'required|string|max:240',
            'support_email' => 'nullable|email|max:254', 'publisher_name' => 'required|string|max:120',
            'google_verification' => 'nullable|string|max:200|regex:/^[A-Za-z0-9_-]+$/',
            'adsense_publisher_id' => 'nullable|string|regex:/^ca-pub-[0-9]{16}$/',
            'indexing_enabled' => 'required|boolean', 'pages' => 'present|array|max:30',
            'pages.*' => 'array:path,title,description,indexable',
            'pages.*.path' => ['required', 'string', 'distinct', Rule::in(SiteSetting::PUBLIC_PATHS)],
            'pages.*.title' => 'required|string|max:100', 'pages.*.description' => 'required|string|max:240',
            'pages.*.indexable' => 'required|boolean',
        ];
    }
}
