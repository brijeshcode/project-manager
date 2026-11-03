<?php

namespace App\Models\Concerns;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Models list `$loggedFields`; every change to one of them on update is written to `activities`.
 * Set `$activityReason` before save to attach an optional reason (deadline changes); `$activityMute` skips fields once.
 */
trait LogsActivity
{
    public ?string $activityReason = null;

    public array $activityMute = [];

    public static function bootLogsActivity(): void
    {
        static::updated(function ($m) {
            foreach ($m->loggedFields as $f) {
                if ($m->isDirty($f) && ! in_array($f, $m->activityMute, true)) {
                    $m->logActivity('changed', $f, $m->activityValue($m->getOriginal($f), $f), $m->activityValue($m->getAttribute($f), $f), $f === 'deadline' ? $m->activityReason : null);
                }
            }
            $m->activityReason = null;
        });
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('id');
    }

    public function logActivity(string $event, ?string $field = null, ?string $old = null, ?string $new = null, ?string $reason = null): Activity
    {
        $reason = trim((string) $reason);

        return $this->activities()->create([
            'user_id' => auth()->id(), 'event' => $event, 'field' => $field,
            'old' => $old, 'new' => $new, 'reason' => $reason === '' ? null : $reason,
        ]);
    }

    /** Assigned/unassigned, like Jira: sync the `assignees` relation and log each side. */
    public function syncAssignees(array $ids): void
    {
        $this->logSync('assignees', $ids, 'assigned', 'unassigned');
    }

    /** Sync a belongsToMany/morphToMany relation, logging every attach and detach with the related name. */
    public function logSync(string $relation, array $ids, string $addEvent, string $removeEvent): void
    {
        $changes = $this->{$relation}()->sync($ids);
        $names = $this->{$relation}()->getRelated()->newQuery()
            ->whereIn('id', array_merge($changes['attached'], $changes['detached']))->pluck('name', 'id');

        foreach ($changes['attached'] as $id) {
            $this->logActivity($addEvent, $relation, null, $names[$id] ?? (string) $id);
        }
        foreach ($changes['detached'] as $id) {
            $this->logActivity($removeEvent, $relation, $names[$id] ?? (string) $id);
        }
        $this->unsetRelation($relation);
    }

    protected function activityValue(mixed $v, ?string $field = null): ?string
    {
        return match (true) {
            $v === null || $v === '' => null,
            $v instanceof Carbon => $v->toDateString(),
            is_bool($v) => $v ? 'yes' : 'no',
            default => (string) $v,
        };
    }
}
