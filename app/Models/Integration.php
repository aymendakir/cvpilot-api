<?php
namespace App\Models;
class Integration extends \Illuminate\Database\Eloquent\Model{
 protected $guarded=[];
 protected $hidden=['secret'];
 protected $casts=['secret'=>'encrypted','settings'=>'array','enabled'=>'boolean','priority'=>'integer','tested_at'=>'datetime'];
}
