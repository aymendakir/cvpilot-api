<?php
namespace App\Models;

class MailSetting extends \Illuminate\Database\Eloquent\Model {
    protected $guarded = [];
    protected $hidden = ['password', 'oauth_client_secret', 'oauth_refresh_token', 'oauth_access_token'];
    protected $casts = [
        'password'=>'encrypted', 'port'=>'integer', 'oauth_client_secret'=>'encrypted',
        'oauth_refresh_token'=>'encrypted', 'oauth_access_token'=>'encrypted', 'oauth_expires_at'=>'datetime',
    ];

    public function clearOAuthTokens(): void {
        $this->oauth_refresh_token = null;
        $this->oauth_access_token = null;
        $this->oauth_expires_at = null;
    }
}
