<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class ChecklistItem extends Model
{
    protected $fillable = ['checklistable_type', 'checklistable_id', 'title', 'is_done', 'position'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
        ];
    }

    public function checklistable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Returns the next position (1-based) scoped to a checklistable owner. */
    public static function nextPosition(string $type, int $id): int
    {
        return (int) static::where('checklistable_type', $type)
            ->where('checklistable_id', $id)
            ->max('position') + 1;
    }

    /**
     * Persists a new ordering given an ordered array of IDs, scoped to one owner.
     * Mirrors Module::reorder pattern.
     */
    public static function reorder(array $orderedIds, string $type, int $id): void
    {
        DB::transaction(function () use ($orderedIds, $type, $id) {
            foreach ($orderedIds as $position => $itemId) {
                static::where('id', $itemId)
                    ->where('checklistable_type', $type)
                    ->where('checklistable_id', $id)
                    ->update(['position' => $position + 1]);
            }
        });
    }
}
