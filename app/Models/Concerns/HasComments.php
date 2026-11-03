<?php

namespace App\Models\Concerns;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasComments
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** Replies are always attached to the thread root, so threads stay one level deep. */
    public function addComment(User $by, string $body, ?int $parentId = null): Comment
    {
        if ($parentId !== null) {
            $parent = $this->comments()->findOrFail($parentId);
            $parentId = $parent->parent_id ?? $parent->id;
        }

        return $this->comments()->create(['author_id' => $by->id, 'body' => trim($body), 'parent_id' => $parentId]);
    }

    /**
     * Root comments, each with its replies in `replies`. Pass a client user to get only what that client may see.
     *
     * @return Collection<int, Comment>
     */
    public function commentThreads(?User $client = null): Collection
    {
        $q = $this->comments()->with('author')->orderBy('id');
        if ($client) {
            $q->visibleToClient($client);
        }
        $all = $q->get();
        $ids = $all->pluck('id');
        $byParent = $all->whereNotNull('parent_id')->groupBy('parent_id');

        return $all->filter(fn ($c) => $c->parent_id === null || ! $ids->contains($c->parent_id))
            ->each(fn ($c) => $c->setRelation('replies', $byParent->get($c->id, collect())))
            ->values();
    }
}
