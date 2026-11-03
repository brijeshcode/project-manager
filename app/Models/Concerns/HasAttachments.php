<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** The project whose membership decides who may download this model's files. */
    abstract public function accessProject(): Project;
}
