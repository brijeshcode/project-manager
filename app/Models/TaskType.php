<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskType extends Model
{
    /** name => [colour, icon]; seeded once, editable by the super admin. "Task" is the default for new tasks. */
    public const DEFAULTS = [
        'Task' => ['#6b7280', 'square-check-big'],
        'Issue' => ['#f97316', 'circle-dot'],
        'Feature' => ['#3b82f6', 'sparkles'],
        'Bug' => ['#ef4444', 'bug'],
        'Change' => ['#8b5cf6', 'arrow-left-right'],
    ];

    protected $fillable = ['name', 'color', 'icon', 'position', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** The type a task gets when none is chosen: the system "Task" type, else the first one. */
    public static function default(): ?self
    {
        return static::where('name', 'Task')->first() ?? static::orderBy('position')->first();
    }
}
