<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'edited_by',
        'previous_values',
        'new_values',
        'created_at',
    ];

    protected $casts = [
        'previous_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];
}
