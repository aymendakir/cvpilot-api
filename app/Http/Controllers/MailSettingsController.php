<?php
namespace App\Http\Controllers;
use App\Models\MailSetting;
use App\Services\PlatformMail;
use Illuminate\Http\Request;

class MailSettingsController {
    public function show() {
        $s=MailSetting::first();
        return $s ? $s->toArray()+['has_password'=>!empty($s->password)] : [
            'host'=>config('mail.mailers.smtp.host',''), 'port'=>config('mail.mailers.smtp.port',587),
            'username'=>config('mail.mailers.smtp.username',''), 'encryption'=>'tls',
            'from_address'=>config('mail.from.address',''), 'from_name'=>config('mail.from.name','CVPilot AI'), 'has_password'=>false,
        ];
    }
    public function save(Request $r) {
        $d=$r->validate(['host'=>'required|string|max:253|regex:/^[a-zA-Z0-9.-]+$/','port'=>'required|integer|between:1,65535',
            'encryption'=>'required|in:tls,ssl','username'=>'nullable|string|max:254','password'=>'nullable|string|max:2000',
            'from_address'=>'required|email|max:254','from_name'=>'required|string|max:120']);
        $s=MailSetting::firstOrNew(['id'=>1]);
        if (empty($d['password'])) unset($d['password']);
        $s->fill($d); $s->save(); AuthController::audit($r,'smtp_updated',$r->user()->id);
        return ['message'=>'SMTP settings saved.'];
    }
    public function test(Request $r, PlatformMail $mail) {
        try { $mail->send($r->user()->email,'CVPilot · SMTP test','emails.notice',['name'=>$r->user()->name,'heading'=>'Your email service is ready','message'=>'This test confirms CVPilot can send email with your saved SMTP settings.']); }
        catch (\Throwable) { return response()->json(['message'=>'Email delivery failed. Check the SMTP host, port and credentials.'],422); }
        return ['message'=>'Test email sent to your administrator address.'];
    }
}
