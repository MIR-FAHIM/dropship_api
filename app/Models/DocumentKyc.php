<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentKyc extends Model
{
    use HasFactory;

    protected $table = 'documents_kyc';

    protected $fillable = [
        'user_id',
        'document_name',
        'type',
        'status',
        'note',
        'document_file_path',
    ];

    protected $casts = [
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
