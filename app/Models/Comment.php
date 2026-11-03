<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    protected $fillable = ['author_id', 'body', 'parent_id', 'edited_by'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Last person to edit the body; null while the comment is untouched. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /**
     * What a client user may see: their own comments, plus every reply inside a thread they started.
     * Replies always point at the thread root (see HasComments::addComment), so one hop is enough.
     */
    public function scopeVisibleToClient(Builder $q, User $client): Builder
    {
        return $q->where(fn ($w) => $w->where('author_id', $client->id)
            ->orWhereIn('parent_id', self::query()->select('id')->where('author_id', $client->id)->whereNull('parent_id')));
    }
}
