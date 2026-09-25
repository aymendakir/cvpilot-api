<?php
namespace App\Models;

class SiteSetting extends \Illuminate\Database\Eloquent\Model {
    protected $guarded = [];
    protected $casts = ['data'=>'array'];

    public const PUBLIC_PATHS = [
        '/', '/about', '/contact', '/privacy', '/terms', '/career-guides', '/cv-builder', '/ats-checker', '/cover-letter',
        '/career-guides/write-a-clear-cv', '/career-guides/tailor-a-cv-to-a-job', '/career-guides/prepare-for-an-interview',
    ];

    public static function defaults(): array {
        return [
            'brand_name'=>'CVPilot AI', 'site_url'=>config('site.url', ''),
            'default_title'=>'CV builder, job matching and interview practice',
            'default_description'=>'Build a clear CV, compare it with a job description, write a cover letter and prepare for interviews with practical career tools.',
            'support_email'=>config('site.support_email', ''), 'publisher_name'=>'CVPilot AI',
            'google_verification'=>'', 'adsense_publisher_id'=>'', 'indexing_enabled'=>true, 'pages'=>[],
        ];
    }

    public static function publicSettings(): array {
        $settings = static::first();
        return array_replace(static::defaults(), array_intersect_key($settings?->data ?? [], static::defaults()));
    }
}
