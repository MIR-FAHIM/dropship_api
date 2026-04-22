<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'added_by',
        'task_image_id',
        'is_active',
        'type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the task that owns the image.
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who added the image.
     */
    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
