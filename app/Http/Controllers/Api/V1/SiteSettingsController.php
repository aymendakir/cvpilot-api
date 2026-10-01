<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Admin\SaveSiteSettingsRequest;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteSettingsController
{
    public function show()
    {
        return SiteSetting::publicSettings();
    }

    public function save(SaveSiteSettingsRequest $request)
    {
        $data = $request->validated();
        $url = parse_url($data['site_url']);
        if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            throw ValidationException::withMessages(['site_url' => 'Enter the website origin only, for example https://example.com, without a page path.']);
        }
        $data['site_url'] = rtrim($data['site_url'], '/');
        foreach (['support_email', 'google_verification', 'adsense_publisher_id'] as $key) {
            $data[$key] = $data[$key] ?? '';
        }
        SiteSetting::updateOrCreate(['id' => 1], ['data' => $data]);
        AuthController::audit($request, 'site_settings_updated', $request->user()->id);

        return $data;
    }

    public function system()
    {
        $connected = false;
        try {
            DB::connection()->getPdo();
            $connected = true;
        } catch (\Throwable) {
        }

        return [
            'php_version' => PHP_VERSION, 'laravel_version' => app()->version(),
            'environment' => app()->environment(), 'database_driver' => config('database.default'),
            'database_connected' => $connected,
            'smtp_configured' => DB::table('mail_settings')->exists() || (bool) config('mail.mailers.smtp.host'),
            'active_integrations' => DB::table('integrations')->where('enabled', true)->count(),
        ];
    }
}
