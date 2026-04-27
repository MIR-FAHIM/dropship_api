<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'created_by',
        'send_to',
        'is_seen',
        'type',
        'is_active',
        'image',
        'module',
    ];

    protected $casts = [
        'is_seen' => 'boolean',
        'is_active' => 'boolean',
    ];
}
