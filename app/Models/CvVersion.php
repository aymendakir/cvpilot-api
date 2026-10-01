<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CvVersion extends Model
{
    protected $guarded = [];

    protected $casts = ['builder_data' => 'array'];
}
