<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MobileAppVersionUpdate extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $table = 'mobile_app_version_updates';

    protected $fillable = [
        'platform',
        'version_name',
        'build_number',
        'min_supported_build',
        'is_force_update',
        'title',
        'release_notes',
        'store_url',
        'is_active',
        'released_at',
        'created_by',
    ];

    protected $casts = [
        'build_number' => 'integer',
        'min_supported_build' => 'integer',
        'is_force_update' => 'boolean',
        'is_active' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function scopeActiveFor(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform)->where('is_active', true);
    }
}
