<?php
namespace App\Models;
class MailSetting extends \Illuminate\Database\Eloquent\Model {
    protected $guarded = [];
    protected $hidden = ['password'];
    protected $casts = ['password'=>'encrypted','port'=>'integer'];
}
