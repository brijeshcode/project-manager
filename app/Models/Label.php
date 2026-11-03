<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Label extends Model
{
    protected $fillable = ['name', 'color', 'icon'];

    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'labelable');
    }
}
