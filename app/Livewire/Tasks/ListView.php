<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ListView extends Component
{
    /** 'idea' | 'task' | '' (all) */
    #[Url]
    public string $stage = '';

    /** 'draft' | 'published' | '' (all) */
    #[Url]
    public string $filterStatus = '';

    /** '1' | '0' | '' (all) */
    #[Url]
    public string $filterClientVisible = '';

    #[Url]
    public ?int $filterLabel = null;

    #[Url]
    public ?int $filterAssignee = null;

    #[Url]
    public ?int $filterModule = null;

    #[Computed]
    public function tasks(): Collection
    {
        $query = Task::visibleTo(auth()->user());

        if ($this->stage === 'idea') {
            $query->where('is_idea', true);
        } elseif ($this->stage === 'task') {
            $query->where('is_idea', false);
        }

        if ($this->filterStatus !== '') {
            $query->where('status', TaskStatus::from($this->filterStatus));
        }

        if ($this->filterClientVisible !== '') {
            $query->where('client_visible', (bool) $this->filterClientVisible);
        }

        if ($this->filterLabel) {
            $query->whereHas('labels', fn ($q) => $q->where('labels.id', $this->filterLabel));
        }

        if ($this->filterAssignee) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $this->filterAssignee));
        }

        if ($this->filterModule) {
            $query->where('module_id', $this->filterModule);
        }

        return $query->orderBy('position')->get();
    }

    public function render()
    {
        return view('livewire.tasks.list-view', [
            'tasks' => $this->tasks,
        ]);
    }
}
