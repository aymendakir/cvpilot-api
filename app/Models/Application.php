<?php
namespace App\Models;
class Application extends \Illuminate\Database\Eloquent\Model{
 protected $guarded=[];
 protected $casts=['applied_at'=>'datetime','application_date'=>'date','reminder_at'=>'datetime','follow_up_at'=>'datetime'];
 public function user(){return $this->belongsTo(User::class);}
}
