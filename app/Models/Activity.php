<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    public const UPDATED_AT = null;

    /** Rows about amounts: only users with the manage-money gate may see them (developers never see money). */
    public const MONEY_FIELDS = ['budget_amount', 'budget_hours', 'hourly_rate', 'billable_hours', 'billing_mode'];

    protected $fillable = ['user_id', 'event', 'field', 'old', 'new', 'reason'];

    public function scopeVisibleTo($q, ?User $user)
    {
        return $user?->can('manage-money') ? $q : $q->where(fn ($w) => $w->whereNull('field')->orWhereNotIn('field', self::MONEY_FIELDS));
    }

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
        $field = match ($this->field) { 'board_column_id' => 'column', 'task_type_id' => 'type', default => null } ?? str_replace('_', ' ', $this->field ?? '');

        return match ($this->event) {
            'assigned' => "assigned {$this->new}",
            'unassigned' => "unassigned {$this->old}",
            'label_added' => "added the label {$this->new}",
            'label_removed' => "removed the label {$this->old}",
            'created' => 'created this resource',
            'viewed' => 'viewed the value',
            'edited' => 'edited the '.($this->field ?? 'resource'),
            'viewer_added' => "gave {$this->new} access",
            'viewer_removed' => "removed access for {$this->old}",
            'deleted' => 'deleted this resource',
            'converted' => "converted this from a subtask of {$this->old}",
            default => $this->field === 'sprint_id'
                ? match (true) {
                    $this->old === null => "added this to {$this->new}",
                    $this->new === null => "removed this from {$this->old}",
                    default => "moved this from {$this->old} to {$this->new}",
                }
                : ($this->field === 'description'
                ? 'updated the description'
                : "changed the {$field} from ".($this->old ?? 'none').' to '.($this->new ?? 'none')),
        };
    }
}
