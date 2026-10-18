<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderAccountingLedger extends Model
{
    use HasFactory;

    protected $table = 'order_accounting_ledgers';

    protected $fillable = [
        'order_id',
        'entry_type',
        'account_code',
        'party_id',
        'party_type',
        'debit',
        'credit',
        'currency',
        'reference_id',
        'description',
    ];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
