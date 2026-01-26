<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shops extends Model
{
    use HasFactory;

    protected $table = 'shops';

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'logo',
        'banner',
        'phone',
        'email',
        'address',
        'zone',
        'district',
        'area',
        'lat',
        'lon',
        'status',
    ];

    protected $casts = [
        'lat' => 'float',
        'lon' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function logo()
    {
        return $this->belongsTo(Upload::class , 'logo' ,);
    }
}
