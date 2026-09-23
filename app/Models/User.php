<?php
namespace App\Models;
class User extends \Illuminate\Database\Eloquent\Model {
 protected $guarded=['id','role','session_version','password','verified_at','suspended'];
 protected $hidden=['password'];
 protected $casts=['verified_at'=>'datetime','suspended'=>'boolean','session_version'=>'integer','preferred_countries'=>'array','work_modes'=>'array','preferences'=>'array'];
}
