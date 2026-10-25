<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTopup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'payment_method',
        'sender_phone',
        'trx_id',
        'receipt_image',
        'status',
        'approved_by',
        'approved_at',
        'admin_note',
    ];

    protected $casts = [
        'amount' => 'float',
        'user_id' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
