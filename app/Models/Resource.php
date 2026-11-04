<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasLabels;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A secret (credentials, links, notes): `value` is markdown, encrypted at rest, and its attachment files are too.
 * Edit = super admin, or (project member) creator / project manager. View = editors + listed viewers.
 * The value is never part of a list query's output (hidden) and never written to the activity log.
 */
class Resource extends Model
{
    use HasAttachments, HasLabels, LogsActivity;

    /** Nothing is logged automatically: the value is secret, so controllers log edits explicitly. */
    public array $loggedFields = [];

    /** Re-reveals by the same user inside this window are not logged again. */
    public const VIEW_LOG_MINUTES = 5;

    protected $fillable = ['project_id', 'title', 'value', 'created_by', 'is_draft'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted', 'is_draft' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Files live outside the DB and attachments are polymorphic (no FK cascade). Activity rows stay as the audit trail.
        static::deleting(function (self $r) {
            $r->attachments->each->delete();
            $r->labels()->detach();
        });
    }

    /** Store a file with its bytes encrypted (Crypt) on the local disk; AttachmentDownloadController decrypts it for authorised viewers. */
    public function attachEncrypted(string $bytes, string $name, string $mime, ?int $userId): Attachment
    {
        $path = 'attachments/'.now()->format('Y/m').'/'.Str::random(40);
        Storage::disk('local')->put($path, Crypt::encryptString($bytes));

        return $this->attachments()->create(['user_id' => $userId, 'path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'encrypted' => true]);
    }

    public function accessProject(): Project
    {
        return $this->project;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'resource_user');
    }

    private static function hasProjectAccess(Project $project, User $user): bool
    {
        return $user->hasRole('client')
            ? $user->client_id !== null && (int) $user->client_id === (int) $project->client_id
            : $project->members()->where('users.id', $user->id)->where('project_members.active', true)->exists();
    }

    public function canEdit(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return ! $user->hasRole('client')
            && ($this->created_by === $user->id || $user->hasRole('project_manager'))
            && self::hasProjectAccess($this->project, $user);
    }

    public function canView(User $user): bool
    {
        return ! $this->is_draft && self::visibleTo($user)->whereKey($this->id)->exists();
    }

    /** Non-draft resources the user may view (see class doc). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('is_draft', false);
        if ($user->hasRole('super_admin')) {
            return $query;
        }
        if ($user->hasRole('client')) {
            return $user->client_id === null
                ? $query->whereRaw('0 = 1')
                : $query->whereHas('project', fn ($p) => $p->where('projects.client_id', $user->client_id))
                    ->whereHas('viewers', fn ($v) => $v->where('users.id', $user->id));
        }

        $query->whereHas('project.members', fn ($m) => $m->where('users.id', $user->id)->where('project_members.active', true));

        return $user->hasRole('project_manager')
            ? $query
            : $query->where(fn ($w) => $w->where('created_by', $user->id)
                ->orWhereHas('viewers', fn ($v) => $v->where('users.id', $user->id)));
    }

    /** Record a reveal of the value, once per user per VIEW_LOG_MINUTES. Never stores the value itself. */
    public function logView(User $user): void
    {
        $recent = $this->activities()->where('event', 'viewed')->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_LOG_MINUTES))->exists();
        $recent || $this->logActivity('viewed');
    }
}
