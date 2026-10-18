<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawRequest extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'amount',
        'reserved_amount',
        'status',
        'payment_method',
        'bank_id',
        'bank_reference',
        'note',
        'type',
        'transferred_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'reserved_amount' => 'float',
        'transferred_at' => 'datetime',
    ];

    /**
     * Get the user that owns the withdraw request.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bank account associated with the withdraw request.
     */
    public function bank()
    {
        return $this->belongsTo(UserBankAccount::class, 'bank_id');
    }
}
