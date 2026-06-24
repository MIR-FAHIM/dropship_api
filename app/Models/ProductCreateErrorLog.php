<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ProductCreateErrorLog extends Model
{
    use HasFactory;

    protected $table = 'product_create_error_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'level',
        'message',
        'file',
        'line',
        'url',
        'method',
        'ip_address',
        'user_agent',
        'request_data',
        'stack_trace',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'line' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
