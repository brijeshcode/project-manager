<?php

namespace App\Livewire\Tasks;

use App\Livewire\Concerns\HandlesMarkdownUploads;
use App\Models\Label;
use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use HandlesMarkdownUploads;

    public Task $task;

    #[Locked]
    public bool $canEdit = false;

    #[Locked]
    public bool $canCost = false;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $deadline = '';

    public string $deadlineReason = '';

    public int $priority = 0;

    public ?int $columnId = null;

    public bool $clientVisible = false;

    public string $status = 'published';

    public bool $isIdea = false;

    public string $scope = 'in';

    public string $estimate = '0';

    public string $price = '';

    public array $assigneeIds = [];

    public array $labelIds = [];

    public string $newLabel = '';

    public string $newLabelColor = '#3b82f6';

    public string $commentBody = '';

    public string $newSubtask = '';

    // subtask panel (opens inside this page)
    public ?int $subtaskId = null;

    public bool $subEditing = false;

    public string $subTitle = '';

    public string $subDescription = '';

    public array $subAssigneeIds = [];

    public array $subLabelIds = [];

    public string $subCommentBody = '';

    // one reply box at a time, for the task or the open subtask
    public ?int $replyTo = null;

    public string $replyScope = 'task';

    public string $replyBody = '';

    public function mount(Task $task): void
    {
        $user = auth()->user();
        // same visibility as lists/board: drafts of others and out-of-section tasks are 404
        abort_unless(Task::visibleTo($user)->whereKey($task->id)->exists(), 404);
        $this->authorize('view', $task->module->project);
        $this->authorize('view', $task);
        $this->task = $task;
        $this->canEdit = $user->can('update', $task);
        $this->canCost = $user->can('updateCostTime', $task);
    }

    protected function uploadTarget(): Model
    {
        $this->authorize('view', $this->task->module->project);

        // ponytail: with the subtask editor open, pasted files go to the subtask
        return $this->subEditing && ($s = $this->subtask()) ? $s : $this->task;
    }

    private function subtask(): ?Subtask
    {
        return $this->subtaskId ? $this->task->subtasks()->find($this->subtaskId) : null;
    }

    public function edit(): void
    {
        $this->authorize('update', $this->task);
        $t = $this->task;
        $this->title = $t->title;
        $this->description = $t->description ?? '';
        $this->deadline = $t->deadline?->toDateString() ?? '';
        $this->deadlineReason = '';
        $this->priority = $t->priority;
        $this->columnId = $t->board_column_id;
        $this->clientVisible = $t->client_visible;
        $this->status = $t->status->value;
        $this->isIdea = $t->is_idea;
        $this->scope = $t->scope->value;
        $this->estimate = (string) $t->estimate_hours;
        $this->price = $t->price === null ? '' : (string) $t->price;
        $this->assigneeIds = $t->assignees()->pluck('users.id')->all();
        $this->labelIds = $t->labels()->pluck('labels.id')->all();
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function addLabel(): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newLabel' => 'required|string|max:50', 'newLabelColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $label = Label::firstOrCreate(['name' => trim($this->newLabel)], ['color' => $this->newLabelColor]);
        $this->labelIds = array_values(array_unique([...$this->labelIds, $label->id]));
        $this->reset('newLabel');
    }

    public function save(): void
    {
        $this->authorize('update', $this->task);
        $project = $this->task->module->project;
        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'deadline' => 'nullable|date',
            'deadlineReason' => 'nullable|string|max:1000',
            'priority' => 'integer|in:0,1,2',
            'columnId' => ['nullable', Rule::exists('board_columns', 'id')->where('project_id', $project->id)],
            'clientVisible' => 'boolean',
            'status' => 'required|in:draft,published',
            'isIdea' => 'boolean',
            'scope' => 'required|in:in,out',
            'estimate' => 'numeric|min:0|max:99999',
            'price' => 'nullable|numeric|min:0|max:9999999.99',
            'assigneeIds' => 'array',
            'labelIds' => 'array',
        ]);

        $t = $this->task;
        $attrs = [
            'title' => trim($this->title),
            'description' => trim($this->description) ?: null,
            'deadline' => $this->deadline ?: null,
            'priority' => $this->priority,
            'board_column_id' => $this->columnId ?? $t->board_column_id,
            'client_visible' => $this->clientVisible,
            'status' => $this->status,
            'is_idea' => $this->isIdea,
        ];
        if ($this->canCost) {
            $attrs += ['scope' => $this->scope, 'estimate_hours' => (float) $this->estimate, 'price' => $this->price === '' ? null : (float) $this->price];
        }
        $t->activityReason = $this->deadlineReason;
        $t->update($attrs);

        $valid = $project->members()->wherePivot('active', true)->pluck('users.id')->all();
        $t->syncAssignees(array_values(array_intersect(array_map('intval', $this->assigneeIds), $valid)));
        $t->syncLabels(Label::whereIn('id', $this->labelIds)->pluck('id')->all());

        $this->editing = false;
        $this->task = $t->fresh();
    }

    public function saveDescription(): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['description' => 'nullable|string|max:20000']);
        $this->task->update(['description' => trim($this->description) ?: null]);
    }

    public function moveColumn(int $columnId): void
    {
        $this->authorize('update', $this->task);
        abort_unless($this->task->module->project->columns()->whereKey($columnId)->exists(), 422);
        $this->task->update(['board_column_id' => $columnId]);
    }

    // ── subtasks ──────────────────────────────────────────────────────────────

    public function addSubtask(): void
    {
        $this->authorize('update', $this->task);
        $this->validate(['newSubtask' => 'required|string|max:255']);
        Subtask::create(['task_id' => $this->task->id, 'title' => trim($this->newSubtask)]);
        $this->reset('newSubtask');
    }

    public function openSubtask(int $id): void
    {
        $s = $this->task->subtasks()->findOrFail($id);
        $this->subtaskId = $s->id;
        $this->subEditing = false;
        $this->subDescription = $s->description ?? '';
        $this->replyTo = null;
    }

    public function closeSubtask(): void
    {
        $this->reset('subtaskId', 'subEditing', 'replyTo');
    }

    public function editSubtask(): void
    {
        $this->authorize('update', $this->task);
        $s = $this->subtask();
        abort_unless($s, 404);
        $this->subTitle = $s->title;
        $this->subDescription = $s->description ?? '';
        $this->subAssigneeIds = $s->assignees()->pluck('users.id')->all();
        $this->subLabelIds = $s->labels()->pluck('labels.id')->all();
        $this->subEditing = true;
    }

    public function saveSubtask(): void
    {
        $this->authorize('update', $this->task);
        $s = $this->subtask();
        abort_unless($s, 404);
        $this->validate([
            'subTitle' => 'required|string|max:255',
            'subDescription' => 'nullable|string|max:20000',
            'subAssigneeIds' => 'array',
            'subLabelIds' => 'array',
        ]);
        $s->update(['title' => trim($this->subTitle), 'description' => trim($this->subDescription) ?: null]);
        $valid = $this->task->module->project->members()->wherePivot('active', true)->pluck('users.id')->all();
        $s->syncAssignees(array_values(array_intersect(array_map('intval', $this->subAssigneeIds), $valid)));
        $s->syncLabels(Label::whereIn('id', $this->subLabelIds)->pluck('id')->all());
        $this->subEditing = false;
    }

    public function deleteSubtask(int $id): void
    {
        $this->authorize('update', $this->task);
        $this->task->subtasks()->findOrFail($id)->delete();
        if ($this->subtaskId === $id) {
            $this->closeSubtask();
        }
    }

    // ── comments ──────────────────────────────────────────────────────────────

    private function subject(string $scope): Model
    {
        if ($scope === 'subtask') {
            $s = $this->subtask();
            abort_unless($s, 404);

            return $s;
        }

        return $this->task;
    }

    public function postComment(string $scope): void
    {
        $this->authorize('view', $this->task->module->project);
        $prop = $scope === 'subtask' ? 'subCommentBody' : 'commentBody';
        $this->validate([$prop => 'required|string|max:10000']);
        $this->subject($scope)->addComment(auth()->user(), $this->$prop);
        $this->reset($prop);
    }

    public function startReply(string $scope, int $commentId): void
    {
        $this->replyScope = $scope;
        $this->replyTo = $commentId;
        $this->replyBody = '';
    }

    public function postReply(): void
    {
        $this->authorize('view', $this->task->module->project);
        $this->validate(['replyBody' => 'required|string|max:10000', 'replyTo' => 'required|integer']);
        $this->subject($this->replyScope)->addComment(auth()->user(), $this->replyBody, $this->replyTo);
        $this->reset('replyBody', 'replyTo');
    }

    /** Comment threads and activity of one model as a single list, newest first. */
    private function timeline(?Model $m): Collection
    {
        if (! $m) {
            return collect();
        }
        $items = $m->commentThreads()->map(fn ($c) => ['at' => $c->created_at, 'kind' => 'comment', 'seq' => $c->id, 'm' => $c]);
        $items = $items->concat($m->activities()->with('user')->get()->map(fn ($a) => ['at' => $a->created_at, 'kind' => 'activity', 'seq' => $a->id, 'm' => $a]));

        return $items->sortByDesc(fn ($i) => [$i['at']?->getTimestamp() ?? 0, $i['seq']])->values();
    }

    public function render()
    {
        $project = $this->task->module->project;
        $sub = $this->subtask();
        $editingAny = $this->editing || $this->subEditing;

        return view('livewire.tasks.show', [
            'project' => $project,
            'module' => $this->task->module,
            'columns' => $project->columns,
            'subtasks' => $this->task->subtasks()->withCount('comments')->with('assignees')->get(),
            'sub' => $sub,
            'timeline' => $this->timeline($this->task),
            'subTimeline' => $this->timeline($sub),
            'candidates' => $editingAny ? $project->members()->wherePivot('active', true)->orderBy('name')->get() : collect(),
            'allLabels' => $editingAny ? Label::orderBy('name')->get() : collect(),
            'assignees' => $this->task->assignees()->orderBy('name')->get(),
            'labels' => $this->task->labels()->orderBy('name')->get(),
            'attachments' => $this->task->attachments()->latest('id')->get(),
        ]);
    }
}
