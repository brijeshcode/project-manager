<?php

namespace App\Livewire\Tasks;

use App\Models\Label;
use App\Models\Module;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\Rule;
use App\Livewire\Concerns\HandlesMarkdownUploads;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    use HandlesMarkdownUploads;

    public ?int $taskId = null;

    public int    $module_id       = 0;
    public string $title           = '';
    public string $description     = '';
    public string $status          = 'draft';
    public bool   $is_idea         = false;
    public int    $priority        = 0;
    public float  $estimate_hours  = 0.0;
    public string $scope           = 'in';
    public ?float $price           = null;
    public bool   $client_visible  = false;
    public bool   $testing_enabled = false;
    public ?string $testing_add_type  = null;
    public ?float  $testing_add_value = null;
    public ?int   $board_column_id = null;   // null = the project's first column
    public int    $position        = 0;
    public string $deadline        = '';

    public array  $labelIds        = [];
    public array  $assigneeIds     = [];

    /** Create-from-copy: the source task (re-validated on save) and whether its subtasks come along. */
    #[Locked]
    public ?int $copyFrom = null;

    public bool $copySubtasks = true;

    public function mount(?Task $task = null): void
    {
        if ($task && $task->exists) {
            $this->authorize('update', $task);
            $this->taskId           = $task->id;
            $this->module_id        = $task->module_id;
            $this->title            = $task->title;
            $this->description      = $task->description ?? '';
            $this->status           = $task->status->value;
            $this->is_idea          = $task->is_idea;
            $this->priority         = $task->priority;
            $this->estimate_hours   = $task->estimate_hours;
            $this->scope            = $task->scope->value;
            $this->price            = $task->price;
            $this->client_visible   = $task->client_visible;
            $this->testing_enabled  = $task->testing_enabled;
            $this->testing_add_type  = $task->testing_add_type;
            $this->testing_add_value = $task->testing_add_value;
            $this->board_column_id  = $task->board_column_id;
            $this->position         = $task->position;
            $this->deadline         = $task->deadline?->toDateString() ?? '';
            $this->labelIds         = $task->labels()->pluck('labels.id')->toArray();
            $this->assigneeIds      = $task->assignees()->pluck('users.id')->toArray();

            return;
        }

        // creating: ?module= preselects the module, ?copy= prefills from another task
        $this->module_id = (int) request()->query('module', $this->moduleOptions()->flatten(1)->first()?->id ?? 0);
        if ($id = request()->query('copy')) {
            $src = Task::visibleTo(auth()->user())->findOrFail($id);
            $this->authorize('update', $src->module->project);
            $this->copyFrom       = $src->id;
            $this->module_id      = $src->module_id;
            $this->title          = 'Copy of '.$src->title;
            $this->description    = $src->description ?? '';
            $this->status         = $src->status->value;
            $this->is_idea        = $src->is_idea;
            $this->priority       = $src->priority;
            $this->estimate_hours = $src->estimate_hours;
            $this->scope          = $src->scope->value;
            $this->price          = $src->price;
            $this->client_visible = $src->client_visible;
            $this->testing_enabled   = $src->testing_enabled;
            $this->testing_add_type  = $src->testing_add_type;
            $this->testing_add_value = $src->testing_add_value;
            $this->deadline       = $src->deadline?->toDateString() ?? '';
            $this->labelIds       = $src->labels()->pluck('labels.id')->toArray();
            $this->assigneeIds    = $src->assignees()->pluck('users.id')->toArray();
        }
    }

    public function updatedModuleId(): void
    {
        $this->board_column_id = null;
    }

    /** Modules the user may add tasks to, grouped by project. */
    private function moduleOptions()
    {
        // ponytail: one policy check per project; fine for a handful of projects
        return Module::with('project')->orderBy('project_id')->orderBy('position')->get()->groupBy('project_id')
            ->filter(fn ($ms) => auth()->user()->can('update', $ms->first()->project));
    }

    protected function uploadTarget(): Model
    {
        // a task that does not exist yet has nowhere to attach files (the editor hides Attach there)
        $task = Task::findOrFail($this->taskId);
        $this->authorize('update', $task);

        return $task;
    }

    public function save(): void
    {
        $data = $this->validate([
            'module_id'         => 'required|exists:modules,id',
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'status'            => 'required|in:draft,published',
            'is_idea'           => 'boolean',
            'priority'          => 'integer|min:0',
            'estimate_hours'    => 'numeric|min:0',
            'scope'             => 'required|in:in,out',
            'price'             => 'nullable|numeric|min:0',
            'client_visible'    => 'boolean',
            'testing_enabled'   => 'boolean',
            'testing_add_type'  => 'nullable|in:percent,fixed',
            'testing_add_value' => 'nullable|numeric|min:0',
            'board_column_id'   => ['nullable', Rule::exists('board_columns', 'id')->where('project_id', Module::find($this->module_id)?->project_id)],
            'position'          => 'integer|min:0',
            'deadline'          => 'nullable|date',
        ]);
        $data['deadline'] = $data['deadline'] ?: null;

        if ($this->taskId) {
            $task = Task::findOrFail($this->taskId);
            $this->authorize('update', $task);
            if (! auth()->user()->can('updateCostTime', $task)) {
                unset($data['estimate_hours'], $data['price']);
            }
            $data['board_column_id'] ??= $task->board_column_id;
            $task->update($data);
            $task->syncLabels($this->labelIds);
            $task->syncAssignees($this->assigneeIds);
        } else {
            $module = Module::findOrFail($this->module_id);
            $this->authorize('update', $module->project);
            $data['creator_id'] = auth()->id();
            if (! auth()->user()->hasAnyRole(['super_admin', 'project_manager'])) {
                unset($data['estimate_hours'], $data['price']);
            }
            $task = Task::create($data);
            $task->labels()->sync($this->labelIds);
            $task->assignees()->sync($this->assigneeIds);
            if ($this->copyFrom && $this->copySubtasks && ($src = Task::visibleTo(auth()->user())->find($this->copyFrom))) {
                $src->copySubtasksTo($task);
            }
        }

        $this->redirect(route('tasks.show', $task), navigate: true);
    }

    public function render()
    {
        $task = $this->taskId ? Task::find($this->taskId) : null;

        return view('livewire.tasks.form', [
            'task'      => $task,
            'moduleOptions' => $this->taskId ? collect() : $this->moduleOptions(),
            'copyCount' => $this->copyFrom ? Task::find($this->copyFrom)?->subtasks()->count() : 0,
            'boardColumns' => Module::find($this->module_id)?->project?->columns ?? collect(),
            'allLabels' => Label::orderBy('name')->get(),
            'allUsers'  => User::orderBy('name')->get(),
        ]);
    }
}
