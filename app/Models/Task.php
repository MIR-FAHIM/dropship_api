<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_title',
        'task_details',
        'is_active',
        'priority_id',
        'task_type_id',
        'is_remind',
        'is_waiting',
        'due_date',
        'start_date',
        'project_id',
        'project_phase_id',
        'prospect_id',
        'created_by',
        'status_id',
        'department_id',
        'completion_percentage',
        'show_completion_percentage',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_remind' => 'boolean',
        'is_waiting' => 'boolean',
        'show_completion_percentage' => 'boolean',
        'completion_percentage' => 'float',
        'due_date' => 'date',
        'start_date' => 'date',
    ];

    public function priority()
    {
        return $this->belongsTo(TaskPriority::class, 'priority_id');
    }

    public function taskType()
    {
        return $this->belongsTo(TaskType::class, 'task_type_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
