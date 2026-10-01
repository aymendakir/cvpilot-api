<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CvTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['design' => 'array', 'sample' => 'array', 'published' => 'boolean'];
}
