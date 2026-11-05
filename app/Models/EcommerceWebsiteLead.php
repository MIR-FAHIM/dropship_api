<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcommerceWebsiteLead extends Model
{
    use HasFactory;

    public const TYPE_INTERESTED_LATER = 'interested_later';
    public const TYPE_QUESTION = 'question';
    public const TYPE_SHOW_PACKAGES = 'show_packages';

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'lead_type',
        'message',
        'status',
        'admin_note',
        'contacted_by',
        'contacted_at',
    ];

    protected $casts = [
        'contacted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contactedBy()
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }
}
