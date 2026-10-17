<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Upload extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'file_original_name',
        'file_name',
        'user_id',
        'file_size',
        'extension',
        'type',
        'external_link',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * Append computed attributes to model JSON
     */
    protected $appends = ['url'];

    /**
     * Accessor for `url` attribute. Returns external_link if set,
     * otherwise returns public storage URL for `file_name`.
     */
    public function getUrlAttribute()
    {
        if ($this->external_link) {
            return $this->external_link;
        }

        if (!empty($this->file_name)) {
            if (str_starts_with($this->file_name, 'http://') || str_starts_with($this->file_name, 'https://')) {
                return $this->file_name;
            }
            return asset('storage/' . ltrim($this->file_name, '/'));
        }

        return null;
    }

    /**
     * Relationship: Upload belongs to a User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
