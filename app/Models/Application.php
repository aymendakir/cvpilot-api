<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    public const STATUSES = ['saved', 'prepared', 'applied', 'interview', 'rejected', 'offer'];

    protected $guarded = [];

    protected $casts = ['applied_at' => 'datetime', 'application_date' => 'date', 'reminder_at' => 'datetime', 'follow_up_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
