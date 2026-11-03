<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'event', 'field', 'old', 'new', 'reason'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One human sentence without the actor, e.g. "changed deadline from 2026-01-01 to 2026-02-01". */
    public function summary(): string
    {
        $field = $this->field === 'board_column_id' ? 'column' : str_replace('_', ' ', $this->field ?? '');

        return match ($this->event) {
            'assigned' => "assigned {$this->new}",
            'unassigned' => "unassigned {$this->old}",
            'label_added' => "added the label {$this->new}",
            'label_removed' => "removed the label {$this->old}",
            'converted' => "converted this from a subtask of {$this->old}",
            default => $this->field === 'description'
                ? 'updated the description'
                : "changed the {$field} from ".($this->old ?? 'none').' to '.($this->new ?? 'none'),
        };
    }
}
