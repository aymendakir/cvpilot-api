<?php
namespace App\Services;
use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;

class PlatformMail {
    public function send(string $to, string $subject, string $view, array $data): void {
        $settings = MailSetting::first();
        if ($settings) {
            $mailer = Mail::build([
                'transport'=>'smtp', 'scheme'=>$settings->encryption==='ssl'?'smtps':'smtp',
                'host'=>$settings->host, 'port'=>$settings->port, 'username'=>$settings->username,
                'password'=>$settings->password, 'timeout'=>15,
                'require_tls'=>$settings->encryption==='tls',
            ]);
            $from = $settings->from_address; $name = $settings->from_name;
        } else {
            if (!config('mail.mailers.smtp.host') || !config('mail.from.address')) throw new \RuntimeException('Email delivery is not configured.');
            $mailer = Mail::mailer(); $from = config('mail.from.address'); $name = config('mail.from.name');
        }
        $mailer->send($view, $data, fn ($m) => $m->from($from,$name)->to($to)->subject($subject));
    }
}
