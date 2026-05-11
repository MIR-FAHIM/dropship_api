<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'balance',
        'support_number',
        'contact_person_name',
        'email',
        'secondary_number',
        'is_active',
        'secret_key',
        'api_key',
        'client_context',
    ];

    protected $casts = [
        'balance'   => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
