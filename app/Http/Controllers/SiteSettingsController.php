<?php
namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteSettingsController {
    public function show() { return SiteSetting::publicSettings(); }

    public function save(Request $request) {
        $data = $request->validate([
            'brand_name'=>'required|string|max:80', 'site_url'=>'required|url:http,https|max:253',
            'default_title'=>'required|string|max:100', 'default_description'=>'required|string|max:240',
            'support_email'=>'nullable|email|max:254', 'publisher_name'=>'required|string|max:120',
            'google_verification'=>'nullable|string|max:200|regex:/^[A-Za-z0-9_-]+$/',
            'adsense_publisher_id'=>'nullable|string|regex:/^ca-pub-[0-9]{16}$/',
            'indexing_enabled'=>'required|boolean', 'pages'=>'present|array|max:30',
            'pages.*'=>'array:path,title,description,indexable',
            'pages.*.path'=>['required','string','distinct',Rule::in(SiteSetting::PUBLIC_PATHS)],
            'pages.*.title'=>'required|string|max:100', 'pages.*.description'=>'required|string|max:240',
            'pages.*.indexable'=>'required|boolean',
        ]);
        $url = parse_url($data['site_url']);
        if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw ValidationException::withMessages(['site_url'=>'Enter the website origin only, for example https://example.com, without a page path.']);
        }
        $data['site_url'] = rtrim($data['site_url'], '/');
        foreach (['support_email', 'google_verification', 'adsense_publisher_id'] as $key) $data[$key] = $data[$key] ?? '';
        SiteSetting::updateOrCreate(['id'=>1], ['data'=>$data]);
        AuthController::audit($request, 'site_settings_updated', $request->user()->id);
        return $data;
    }

    public function system() {
        $connected = false;
        try { DB::connection()->getPdo(); $connected = true; } catch (\Throwable) {}
        return [
            'php_version'=>PHP_VERSION, 'laravel_version'=>app()->version(),
            'environment'=>app()->environment(), 'database_driver'=>config('database.default'),
            'database_connected'=>$connected,
            'smtp_configured'=>DB::table('mail_settings')->exists() || (bool) config('mail.mailers.smtp.host'),
            'active_integrations'=>DB::table('integrations')->where('enabled', true)->count(),
        ];
    }
}
