<?php
return [
 'paths'=>['api/*'],
 'allowed_methods'=>['*'],
 'allowed_origins'=>array_values(array_filter([env('FRONTEND_URL'),env('APP_URL')])),
 'allowed_origins_patterns'=>[],
 'allowed_headers'=>['Content-Type','Accept','X-CSRF-TOKEN','X-Requested-With'],
 'exposed_headers'=>['Retry-After'],
 'max_age'=>600,
 'supports_credentials'=>true,
];
