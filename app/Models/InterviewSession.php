<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewSession extends Model
{
    protected $guarded = [];

    protected $casts = ['transcript' => 'array'];
}
