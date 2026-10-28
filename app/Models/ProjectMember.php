<?php

namespace App\Models;

use App\Enums\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    protected $table = 'project_members';

    protected $fillable = [
        'project_id',
        'user_id',
        'role_on_project',
        'access_scope',
        'active',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'access_scope' => AccessScope::class,
            'active'       => 'boolean',
            'removed_at'   => 'datetime',
        ];
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
