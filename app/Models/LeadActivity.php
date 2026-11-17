<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'admin_id', 'type', 'from_value', 'to_value', 'note', 'created_at'];

    protected $casts = [
        'lead_id' => 'integer',
        'admin_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
