<?php

namespace App\Livewire\Concerns;

use App\Models\Task;

/** Show/hide one project task to the client straight from a list (needs a `$project` property). */
trait TogglesTaskVisibility
{
    public function toggleTaskVisibility(int $taskId): void
    {
        $this->authorize('update', $this->project);
        $t = Task::whereIn('module_id', $this->project->modules()->select('id'))->findOrFail($taskId);
        $t->update(['client_visible' => ! $t->client_visible]);
        $this->dispatch('toast', type: 'success', message: $t->client_visible ? "{$t->title} is now visible to the client." : "{$t->title} is now hidden from the client.");
    }
}
