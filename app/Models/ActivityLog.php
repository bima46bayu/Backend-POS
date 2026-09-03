<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_role',
        'store_location_id',
        'method',
        'path',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'status_code',
        'ip',
        'user_agent',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
