<?php
namespace App\Models;
class CvDocument extends \Illuminate\Database\Eloquent\Model{
 protected $guarded=[];
 protected $hidden=['disk_path','extracted_text'];
 protected $casts=['is_primary'=>'boolean','expires_at'=>'datetime'];
}
