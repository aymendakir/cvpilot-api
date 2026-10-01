<?php

return ['driver' => env('SESSION_DRIVER', 'file'), 'table' => 'sessions', 'connection' => env('SESSION_CONNECTION'), 'lifetime' => 120, 'expire_on_close' => false, 'encrypt' => true, 'files' => storage_path('framework/sessions'), 'cookie' => 'cvpilot_session', 'path' => '/', 'domain' => env('SESSION_DOMAIN'), 'secure' => env('SESSION_SECURE_COOKIE', true), 'http_only' => true, 'same_site' => env('SESSION_SAME_SITE', 'lax'), 'lottery' => [2, 100]];
