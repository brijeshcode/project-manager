<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    /** Never accepted, whatever the mime says. */
    public const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'msi', 'sh', 'js', 'mjs', 'jar', 'vbs', 'ps1', 'html', 'htm', 'svg', 'dll', 'scr'];

    /** Raster types that are safe to show inline; everything else downloads. */
    public const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    protected $fillable = ['user_id', 'path', 'name', 'mime', 'size', 'encrypted'];

    protected static function booted(): void
    {
        static::deleted(fn (self $a) => Storage::disk('local')->delete($a->path));
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return route('attachments.download', $this, false);
    }

    public function isInlineImage(): bool
    {
        return in_array($this->mime, self::INLINE_MIMES, true);
    }
}
