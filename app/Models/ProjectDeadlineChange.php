<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDeadlineChange extends Model
{
    protected $fillable = ['project_id', 'user_id', 'old_deadline', 'new_deadline', 'reason'];

    protected function casts(): array
    {
        return ['old_deadline' => 'date', 'new_deadline' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
