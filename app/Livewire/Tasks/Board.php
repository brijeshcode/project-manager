<?php

namespace App\Livewire\Tasks;

use App\Enums\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Board extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    /**
     * Returns tasks grouped by board column, restricted to project tasks visible
     * to the authenticated user (drafts of others excluded via visibleTo scope).
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function columns(): array
    {
        $projectModuleIds = $this->project->modules()->pluck('id');

        $tasks = Task::visibleTo(auth()->user())
            ->whereIn('module_id', $projectModuleIds)
            ->orderBy('position')
            ->get()
            ->groupBy('board_column');

        // Ensure every column key exists even if empty
        return collect(BoardColumn::ordered())
            ->mapWithKeys(fn (BoardColumn $col) => [
                $col->value => $tasks->get($col->value, collect()),
            ])
            ->all();
    }

    /**
     * Handle a drag-and-drop event emitted by SortableJS via Livewire.
     * Only moves the task if the current user can update it via ProjectPolicy.
     */
    public function taskMoved(int $taskId, string $column, int $position): void
    {
        $this->authorize('view', $this->project);

        $task = Task::visibleTo(auth()->user())
            ->whereIn('module_id', $this->project->modules()->pluck('id'))
            ->findOrFail($taskId);

        $task->moveTo($column, $position);

        unset($this->columns);
    }

    public function render()
    {
        return view('livewire.tasks.board', [
            'columns'     => $this->columns,
            'columnOrder' => BoardColumn::ordered(),
        ]);
    }
}
