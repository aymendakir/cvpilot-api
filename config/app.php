<?php
// An empty string lets Composer bootstrap before .env exists without fixing a backend host.
return ['name'=>'CVPilot','env'=>env('APP_ENV','production'),'debug'=>(bool)env('APP_DEBUG',false),'url'=>env('APP_URL',''),'timezone'=>'UTC','locale'=>'en','fallback_locale'=>'en','key'=>env('APP_KEY'),'cipher'=>'AES-256-CBC','previous_keys'=>[]];
