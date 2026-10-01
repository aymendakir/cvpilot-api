<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $guarded = ['id', 'role', 'session_version', 'password', 'verified_at', 'suspended'];

    protected $hidden = ['password'];

    protected $casts = ['verified_at' => 'datetime', 'suspended' => 'boolean', 'session_version' => 'integer', 'preferred_countries' => 'array', 'work_modes' => 'array', 'preferences' => 'array'];
}
