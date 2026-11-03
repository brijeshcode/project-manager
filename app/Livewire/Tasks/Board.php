<?php

namespace App\Livewire\Tasks;

use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\Task;
use App\Support\BoardTemplates;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Board extends Component
{
    public Project $project;

    #[Url]
    public ?int $filterModule = null;

    public bool $grouped = false;      // group by module: one swimlane per module
    public bool $managing = false;     // show the manage-columns panel
    public string $newColumnName = '';
    public string $newColumnStage = 'active';
    public array $deleteTarget = [];   // column id => column id its tasks move to

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    /** Project columns in order. */
    #[Computed]
    public function boardColumns(): Collection
    {
        return $this->project->columns()->get();
    }

    /**
     * Tasks grouped by board_column_id, restricted to project tasks visible
     * to the authenticated user (drafts of others excluded via visibleTo scope).
     *
     * @return Collection<int, Collection>
     */
    #[Computed]
    public function columns(): Collection
    {
        return Task::visibleTo(auth()->user())
            ->whereIn('module_id', $this->project->modules()->pluck('id'))
            ->when($this->filterModule, fn ($q) => $q->where('module_id', $this->filterModule))
            ->with(['module', 'subtasks.checklistItems'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('board_column_id');
    }

    /**
     * Handle a drag-and-drop event emitted by SortableJS via Livewire.
     * Only moves tasks of this project that the user can see.
     */
    public function taskMoved(int $taskId, int $column, int $position, array $order = []): void
    {
        $this->authorize('view', $this->project);

        try {
            $task = Task::visibleTo(auth()->user())
                ->whereIn('module_id', $this->project->modules()->pluck('id'))
                ->findOrFail($taskId);
        } catch (ModelNotFoundException) {
            return; // foreign-project task; silently no-op
        }

        // `order` = card ids of the target column after the drop; the slot that matters is among this task's own module
        if ($order) {
            $sameModule = Task::whereIn('id', array_map('intval', $order))->where('module_id', $task->module_id)->pluck('id')->all();
            $idx = array_search($taskId, array_values(array_filter(array_map('intval', $order), fn ($i) => in_array($i, $sameModule, true))), true);
            $position = $idx === false ? $position : $idx + 1;
        }

        $task->moveTo($column, $position);

        unset($this->columns);
    }

    // ── manage columns (project editors) ─────────────────────────────────────

    public function addColumn(): void
    {
        $this->authorize('update', $this->project);
        $this->validate([
            'newColumnName'  => 'required|string|max:60',
            'newColumnStage' => ['required', Rule::in(array_keys(BoardTemplates::STAGES))],
        ]);

        // goes just before the locked last column
        $last = $this->project->columns()->get()->last();
        $this->project->columns()->create(['name' => trim($this->newColumnName), 'stage' => $this->newColumnStage, 'position' => $last?->position ?? 1]);
        $last?->increment('position');
        $this->reset('newColumnName');
        unset($this->boardColumns);
    }

    public function renameColumn(int $id, string $name): void
    {
        $this->authorize('update', $this->project);
        if (trim($name) !== '') {
            $this->project->columns()->whereKey($id)->update(['name' => mb_substr(trim($name), 0, 60)]);
        }
        unset($this->boardColumns);
    }

    public function setStage(int $id, string $stage): void
    {
        $this->authorize('update', $this->project);
        $col = $this->project->columns()->findOrFail($id);
        if (! $col->isLocked() && array_key_exists($stage, BoardTemplates::STAGES)) {
            $col->update(['stage' => $stage]);
        }
        unset($this->boardColumns);
    }

    /** Swap with the neighbour; the locked first/last columns stay where they are. */
    public function moveColumn(int $id, int $dir): void
    {
        $this->authorize('update', $this->project);
        $ids = $this->project->columns()->pluck('id')->all();
        $i = array_search($id, $ids, true);
        if ($i === false) {
            return;
        }
        $j = $i + ($dir < 0 ? -1 : 1);
        $last = count($ids) - 1;
        if ($i < 1 || $i >= $last || $j < 1 || $j >= $last) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $pos => $cid) {
            BoardColumn::whereKey($cid)->update(['position' => $pos + 1]);
        }
        unset($this->boardColumns);
    }

    /** Deleting needs a target column for the tasks; first and last columns can't be deleted. */
    public function deleteColumn(int $id): void
    {
        $this->authorize('update', $this->project);
        $col = $this->project->columns()->findOrFail($id);
        if ($col->isLocked()) {
            return;
        }
        $target = (int) ($this->deleteTarget[$id] ?? 0);
        if (! $target || $target === $id || ! $this->project->columns()->whereKey($target)->exists()) {
            $this->addError("deleteTarget.$id", 'Choose a column to move its tasks to.');

            return;
        }
        Task::where('board_column_id', $id)->get()->each->update(['board_column_id' => $target]); // per model so the date automation runs
        $col->delete();
        unset($this->deleteTarget[$id]);
        unset($this->boardColumns, $this->columns);
    }

    public function render()
    {
        $modules = $this->project->modules()->get();

        return view('livewire.tasks.board', [
            'modules'   => $modules,
            'lanes'     => $this->grouped ? $modules->when($this->filterModule, fn ($m) => $m->where('id', $this->filterModule)) : collect([null]),
            'stages'    => BoardTemplates::STAGES,
            'canManage' => auth()->user()->can('update', $this->project),
        ]);
    }
}
