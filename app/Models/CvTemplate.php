<?php
namespace App\Models;
class CvTemplate extends \Illuminate\Database\Eloquent\Model {
    protected $guarded = [];
    protected $casts = ['design'=>'array','sample'=>'array','published'=>'boolean'];
}
