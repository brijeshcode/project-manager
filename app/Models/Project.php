<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Project extends Model
{
    protected $fillable = [
        'client_id',
        'name',
        'buffer_percent',
        'start_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'buffer_percent' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
                    ->withPivot(['role_on_project', 'access_scope', 'active', 'removed_at'])
                    ->withTimestamps();
    }

    public function currency(): string
    {
        return $this->client->currency;
    }
}
