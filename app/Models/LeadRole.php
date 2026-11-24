<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A lead role added by an admin in the Lead Manager (built-in ones are Lead::ROLES). */
class LeadRole extends Model
{
    protected $fillable = ['value', 'label', 'created_by'];
}
