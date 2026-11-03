<?php

namespace App\Models\Concerns;

use App\Models\Label;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasLabels
{
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable');
    }

    /** Sync labels, logging added/removed when the model also logs activity. */
    public function syncLabels(array $ids): void
    {
        method_exists($this, 'logSync')
            ? $this->logSync('labels', $ids, 'label_added', 'label_removed')
            : $this->labels()->sync($ids);
    }
}
