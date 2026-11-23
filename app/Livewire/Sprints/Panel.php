<?php

namespace App\Livewire\Sprints;

use App\Livewire\Concerns\TogglesTaskAssignee;
use App\Livewire\Concerns\TogglesTaskVisibility;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskType;
use App\Services\ProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The project's Sprint tab: a Plan view (Active sprint / Next sprints / Backlog, drag and drop); the active sprint board is the Board tab
 * with its Active sprint switch. Viewing needs project access, every change needs `update` on the project.
 */
class Panel extends Component
{
    use TogglesTaskAssignee, TogglesTaskVisibility;

    public Project $project;

    #[Locked]
    public bool $canManage = false;

    /** A client user in the portal: read-only, client-visible tasks only, no backlog, people or hours. Derived from the user, never passed in. */
    #[Locked]
    public bool $client = false;

    #[Url(as: 'spUser')]
    public string $user = '';

    #[Url(as: 'spType')]
    public string $type = '';

    #[Url(as: 'spLabel')]
    public string $label = '';

    /** Sprint form: null | create | edit | start. */
    public ?string $formMode = null;

    public ?int $formId = null;

    public string $name = '';

    public string $goal = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public int $weeks = 1;

    /** Complete dialog: sprint id and what happens to unfinished tasks (next | backlog). */
    public ?int $completeId = null;

    public string $rollover = 'next';

    /** Expanded past sprints (ids). */
    public array $openPast = [];

    public function mount(Project $project): void
    {
        $this->client = auth()->user()->hasRole('client');
        $this->authorize($this->client ? 'viewAsClient' : 'view', $project);
        $this->project = $project;
        $this->canManage = ! $this->client && auth()->user()->can('update', $project);
    }

    // ── sprint form: create / edit / start ───────────────────────────────────

    public function openCreate(): void
    {
        $this->authorize('update', $this->project);
        $this->resetErrorBag();
        $this->formMode = 'create';
        $this->formId = null;
        $this->name = Sprint::nextName($this->project->id);
        $this->goal = '';
        $this->weeks = 1;
        $this->startsOn = today()->toDateString();
        $this->endsOn = '';
    }

    public function nextMonday(): void
    {
        $this->startsOn = today()->next(Carbon::MONDAY)->toDateString();
    }

    public function openEdit(int $id, string $mode = 'edit'): void
    {
        $this->authorize('update', $this->project);
        $s = $this->project->sprints()->whereIn('status', ['planned', 'active'])->findOrFail($id);
        abort_if($mode === 'start' && $s->status !== 'planned', 422);
        $this->resetErrorBag();
        $this->formMode = $mode;
        $this->formId = $s->id;
        $this->name = $s->name;
        $this->goal = $s->goal ?? '';
        $this->startsOn = $s->starts_on->toDateString();
        $this->endsOn = $s->ends_on->toDateString();
    }

    public function openStart(int $id): void
    {
        $this->openEdit($id, 'start');
    }

    public function closeForm(): void
    {
        $this->reset('formMode', 'formId', 'completeId');
        $this->resetErrorBag();
    }

    public function saveForm(): void
    {
        $this->authorize('update', $this->project);
        abort_unless(in_array($this->formMode, ['create', 'edit', 'start'], true), 422);

        $create = $this->formMode === 'create';
        $this->validate([
            'name'     => 'required|string|max:80',
            'goal'     => 'nullable|string|max:1000',
            'startsOn' => 'required|date',
            'weeks'    => $create ? 'required|in:1,2' : 'nullable',
            'endsOn'   => $create ? 'nullable' : 'required|date',
        ]);
        $start = Carbon::parse($this->startsOn);
        $end = $create ? $start->copy()->addDays($this->weeks * 7 - 1) : Carbon::parse($this->endsOn);
        try {
            Sprint::checkDates($start, $end);
        } catch (ValidationException $e) {
            $this->addError('endsOn', $e->errors()['ends_on'][0]);

            return;
        }
        $data = ['name' => trim($this->name), 'goal' => trim($this->goal) ?: null, 'starts_on' => $start, 'ends_on' => $end];

        if ($create) {
            $this->project->sprints()->create($data + ['status' => 'planned']);
        } else {
            $sprint = $this->project->sprints()->whereIn('status', ['planned', 'active'])->findOrFail($this->formId);
            try {
                if ($this->formMode === 'start') {
                    $sprint->start(); // checks first: nothing is saved when another sprint is active
                }
                $sprint->update($data);
            } catch (ValidationException $e) {
                $this->addError('sprint', $e->errors()['sprint'][0]);

                return;
            }
        }
        $this->closeForm();
    }

    public function deleteSprint(int $id): void
    {
        $this->authorize('update', $this->project);
        $this->project->sprints()->planned()->findOrFail($id)->deletePlanned();
    }

    // ── completing ───────────────────────────────────────────────────────────

    public function openComplete(int $id): void
    {
        $this->authorize('update', $this->project);
        $this->project->sprints()->active()->findOrFail($id);
        $this->completeId = $id;
        $this->rollover = 'next';
    }

    public function completeSprint(): void
    {
        $this->authorize('update', $this->project);
        abort_unless(in_array($this->rollover, ['next', 'backlog'], true), 422);
        $this->project->sprints()->active()->findOrFail($this->completeId)->complete($this->rollover);
        $this->closeForm();
    }

    public function togglePast(int $id): void
    {
        $this->openPast = in_array($id, $this->openPast, true) ? array_values(array_diff($this->openPast, [$id])) : [...$this->openPast, $id];
    }

    // ── drag and drop ────────────────────────────────────────────────────────

    /**
     * Dropped into a list: `$sprintId` is a planned/active sprint of this project, or 0 for the backlog; `$ids` is the
     * list after the drop. Done tasks never move (they stay with their sprint) and nothing goes into or out of a
     * completed sprint. Ids of other projects are ignored. The order is applied per module (see Task::reorderMixed).
     */
    public function moveTask(int $taskId, int $sprintId, array $ids = []): void
    {
        $this->authorize('update', $this->project);

        $task = $this->projectTasks()->with('column', 'sprint')->find($taskId);
        $target = $sprintId ? $this->project->sprints()->whereIn('status', ['planned', 'active'])->find($sprintId) : null;
        if (! $task || ($sprintId && ! $target) || $task->column?->stage === 'done' || $task->sprint?->status === 'completed') {
            return;
        }

        $task->update(['sprint_id' => $target?->id]);
        foreach ($task->capacityWarnings() as $msg) {
            $this->dispatch('toast', type: 'warning', message: $msg);
        }
        $this->reorderList($ids);
    }

    /** Drag inside one list. Only this project's tasks are touched. */
    public function reorderList(array $ids): void
    {
        $this->authorize('update', $this->project);
        $ids = collect($ids)->map(fn ($i) => (int) $i)->unique()->values();
        $ok = $this->projectTasks()->whereIn('id', $ids)->pluck('id');
        Task::reorderMixed($ids->filter(fn ($i) => $ok->contains($i))->all());
    }

    private function projectTasks()
    {
        return Task::approved()->whereIn('module_id', $this->project->modules()->pluck('id'));
    }

    // ── data ─────────────────────────────────────────────────────────────────

    /** Counted tasks this user may see: a client only gets client-visible tasks of client-visible modules. */
    private function visibleTasks()
    {
        return $this->client
            ? Task::counted()->where('client_visible', true)->whereIn('module_id', $this->project->modules()->where('client_visible', true)->pluck('id'))
            : Task::visibleTo(auth()->user())->counted()->whereIn('module_id', $this->project->modules()->pluck('id'));
    }

    /** Counted tasks the user may see that are in an open sprint or the backlog, filtered, grouped by module then position. */
    private function openTasks(Collection $openSprints): Collection
    {
        $ids = $openSprints->pluck('id');

        return $this->visibleTasks()
            ->where(fn ($q) => $q->whereNull('sprint_id')->orWhereIn('sprint_id', $ids))
            ->when($this->user !== '', fn ($q) => $q->whereHas('assignees', fn ($a) => $a->where('users.id', (int) $this->user)))
            ->when($this->type !== '', fn ($q) => $q->where('task_type_id', (int) $this->type))
            ->when($this->label !== '', fn ($q) => $q->whereHas('labels', fn ($l) => $l->where('labels.id', (int) $this->label)))
            ->with(['project', 'module.project.client', 'type', 'labels', 'assignees', 'column', 'checklistItems', 'subtasks'])
            ->get()
            // done tasks sink to the bottom of their list so the next open task is always on top
            ->sortBy([fn ($a, $b) => ($a->column?->stage === 'done') <=> ($b->column?->stage === 'done'), fn ($a, $b) => $a->module->position <=> $b->module->position, ['position', 'asc'], ['id', 'asc']])
            ->values();
    }

    public function render()
    {
        $sprints = $this->project->sprints()->get();
        $tasks = $this->openTasks($sprints->whereIn('status', ['planned', 'active']));
        $past = $sprints->where('status', 'completed')->sortByDesc('ends_on')->values();
        $pastTasks = $past->isEmpty() ? collect() : $this->visibleTasks()
            ->whereIn('sprint_id', $past->pluck('id'))->with(['project', 'module.project.client', 'type', 'labels', 'assignees', 'column', 'subtasks'])
            ->orderBy('position')->get()->groupBy('sprint_id');
        $past = $this->client ? $past->filter(fn ($p) => $pastTasks->has($p->id))->values() : $past;
        $active = $sprints->firstWhere('status', 'active');
        $hasTasks = fn ($s) => ! $this->client || $tasks->contains('sprint_id', $s->id);
        $active = $active && $hasTasks($active) ? $active : null;

        return view('livewire.sprints.panel', [
            'active'      => $active,
            'activeTasks' => $active ? $tasks->where('sprint_id', $active->id)->values() : collect(),
            'planned'     => $sprints->where('status', 'planned')->filter($hasTasks)->values(),
            'client'      => $this->client,
            'backlog'     => $tasks->whereNull('sprint_id')->reject(fn (Task $t) => $t->column?->stage === 'done')->values(),
            'tasks'       => $tasks,
            'past'        => $past,
            'pastTasks'   => $pastTasks,
            'progress'    => app(ProgressService::class),
            'users'       => $this->client ? collect() : $this->project->members()->orderBy('name')->get(),
            'candidates'  => $this->client ? null : $this->assignable(),
            'types'       => TaskType::orderBy('position')->get(),
            'labels'      => $this->client ? collect() : Label::orderBy('name')->get(),
            'completing'  => $this->completeId ? $sprints->firstWhere('id', $this->completeId) : null,
        ]);
    }
}
