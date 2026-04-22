<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssignedTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'added_by',
        'assign_to',
        'note',
    ];

    /**
     * Get the task for this assignment.
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who assigned the task.
     */
    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Get the user to whom the task is assigned.
     */
    public function assignTo()
    {
        return $this->belongsTo(User::class, 'assign_to');
    }
}
