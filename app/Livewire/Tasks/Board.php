<?php

namespace App\Livewire\Tasks;

use App\Livewire\Concerns\TogglesTaskAssignee;
use App\Models\BoardColumn;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskType;
use App\Support\BoardTemplates;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Board extends Component
{
    use TogglesTaskAssignee { toggleTaskAssignee as private toggleAssignee; }

    public Project $project;

    /** Legacy single-module link (?module=ID): folded into $moduleIds. */
    #[Url(as: 'module')]
    public ?int $filterModule = null;

    // Filters, all in the URL. Multi-select: a card must match every active filter, and any ONE chosen value within it.
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'modules')]
    public array $moduleIds = [];

    /** User ids, or 'none' for unassigned. */
    #[Url(as: 'assignees')]
    public array $assigneeIds = [];

    #[Url(as: 'types')]
    public array $typeIds = [];

    #[Url(as: 'labels')]
    public array $labelIds = [];

    #[Url(as: 'prios')]
    public array $prios = [];

    /** Shown inside the project page (its breadcrumb already says where we are): no own back link or title. */
    #[Locked]
    public bool $embedded = false;

    /** Board tab switch: all | sprint (the active sprint only). */
    #[Url(as: 'scope')]
    public string $scope = '';

    /** No scope in the URL and no active sprint: showing all tasks instead (hint). */
    #[Locked]
    public bool $fellBack = false;

    #[Url(as: 'group')]
    public bool $grouped = false;      // group by module: one swimlane per module
    public bool $managing = false;     // show the manage-columns panel
    public string $newColumnName = '';
    public string $newColumnStage = 'active';
    public array $deleteTarget = [];   // column id => column id its tasks move to

    /** Task shown in the slide-over panel (?task=ID); only ever set to a task the user may open (see openTask). */
    #[Url(as: 'task', history: true)]
    public ?int $taskId = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->taskId = $this->openableTask($this->taskId)?->id;
        $this->updatedFilterModule();
        if (! in_array($this->scope, ['sprint', 'all'], true)) {
            // default: the active sprint; without one, everything so the board is not empty
            $this->scope = $this->activeSprint ? 'sprint' : 'all';
            $this->fellBack = $this->scope === 'all';
        }
    }

    /** Same visibility as the task page: visible to the user, in this project, and viewable by policy. */
    private function openableTask(?int $id): ?Task
    {
        $task = $id ? Task::visibleTo(auth()->user())->whereIn('module_id', $this->project->modules()->select('id'))->find($id) : null;

        return $task && auth()->user()->can('view', $task) ? $task : null;
    }

    public function openTask(int $id): void
    {
        $this->taskId = $this->openableTask($id)?->id;
    }

    public function closeTask(): void
    {
        $this->taskId = null;
        $this->dispatch('task-panel-closed');
    }

    /** An edit made inside the panel: refetch the cards. */
    #[On('task-panel-updated')]
    public function panelUpdated(): void
    {
        unset($this->columns);
    }

    public function toggleTaskAssignee(int $taskId, int $userId): void
    {
        $this->toggleAssignee($taskId, $userId);
        $this->taskId === $taskId && $this->dispatch('board-task-changed');
    }

    public function updatedFilterModule(): void
    {
        if ($this->filterModule) {
            $this->moduleIds = [...$this->moduleIds, (string) $this->filterModule];
            $this->filterModule = null;
        }
    }

    private function ints(array $v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', array_filter($v, 'is_scalar')), fn ($i) => $i > 0)));
    }

    /** Prefixed lists of the filter state, keyed like the removeFilter() keys. */
    private const FILTERS = ['modules' => 'moduleIds', 'assignees' => 'assigneeIds', 'types' => 'typeIds', 'labels' => 'labelIds', 'prios' => 'prios'];

    public function removeFilter(string $key, string $value): void
    {
        if ($prop = self::FILTERS[$key] ?? null) {
            $this->$prop = array_values(array_filter($this->$prop, fn ($v) => (string) $v !== $value));
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filterModule', ...array_values(self::FILTERS));
    }

    /** Project columns in order. */
    #[Computed]
    public function boardColumns(): Collection
    {
        return $this->project->columns()->get();
    }

    #[Computed]
    public function activeSprint(): ?\App\Models\Sprint
    {
        return $this->project->sprints()->active()->first();
    }

    /** Sprint to restrict to: the active one under "Active sprint" (0 = none exists, so nothing matches), or null for all tasks. */
    private function sprintFilter(): ?int
    {
        return $this->scope === 'sprint' ? ($this->activeSprint?->id ?? 0) : null;
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
            ->when($this->ints($this->moduleIds), fn ($q, $ids) => $q->whereIn('module_id', $ids))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->when($this->prios, fn ($q) => $q->whereIn('priority', array_map('intval', $this->prios)))
            ->when($this->ints($this->typeIds), fn ($q, $ids) => $q->whereIn('task_type_id', $ids))
            ->when($this->ints($this->labelIds), fn ($q, $ids) => $q->whereHas('labels', fn ($l) => $l->whereIn('labels.id', $ids)))
            ->when($this->assigneeIds, function ($q) {
                $ids = $this->ints($this->assigneeIds);
                $none = in_array('none', $this->assigneeIds, true);
                $q->where(function ($w) use ($ids, $none) {
                    $ids && $w->whereHas('assignees', fn ($a) => $a->whereIn('users.id', $ids));
                    $none && ($ids ? $w->orDoesntHave('assignees') : $w->doesntHave('assignees'));
                });
            })
            ->when($this->sprintFilter() !== null, fn ($q) => $q->where('sprint_id', $this->sprintFilter()))
            ->with(['module', 'type', 'labels', 'subtasks.checklistItems', 'checklistItems', 'assignees'])
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

        $oldStage = $task->column?->stage;
        $mixed = $order && Task::whereIn('id', array_map('intval', $order))->where('module_id', '!=', $task->module_id)->exists();
        $task->moveTo($column, $position);

        // The browser already shows the result: skip the re-render (no flash) when a same-stage move of a lone module's cards left nothing to correct.
        if (! $mixed && $order && $oldStage === BoardColumn::find($column)?->stage) {
            $this->skipRender();
            $this->taskId === $taskId && $this->dispatch('board-task-changed');

            return;
        }
        unset($this->columns);
        $this->taskId === $taskId && $this->dispatch('board-task-changed');
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
        $canManage = auth()->user()->can('update', $this->project);

        return view('livewire.tasks.board', [
            'modules'   => $modules,
            'lanes'     => $this->grouped ? $modules->when($this->ints($this->moduleIds), fn ($m, $ids) => $m->whereIn('id', $ids)) : collect([null]),
            'candidates' => $canManage ? $this->assignable() : null,
            'people'    => $this->project->members()->orderBy('name')->get(),
            'types'     => TaskType::orderBy('position')->get(),
            'allLabels' => Label::orderBy('name')->get(),
            'stages'    => BoardTemplates::STAGES,
            'canManage' => $canManage,
            'panelTask' => $this->openableTask($this->taskId),
        ]);
    }
}
