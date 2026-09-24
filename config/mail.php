<?php

$encryption = env('MAIL_ENCRYPTION', (int) env('MAIL_PORT', 587) === 465 ? 'ssl' : 'tls');

return [
    'default'=>'smtp',
    'mailers'=>['smtp'=>[
        'transport'=>'smtp',
        'scheme'=>$encryption === 'ssl' ? 'smtps' : 'smtp',
        'host'=>env('MAIL_HOST'), 'port'=>env('MAIL_PORT', 587),
        'username'=>env('MAIL_USERNAME'), 'password'=>env('MAIL_PASSWORD'),
        'require_tls'=>true, 'timeout'=>15,
    ]],
    'from'=>['address'=>env('MAIL_FROM_ADDRESS'), 'name'=>env('MAIL_FROM_NAME', 'CVPilot AI')],
    'frontend_url'=>env('FRONTEND_URL'),
];
