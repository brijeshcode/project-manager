<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\HandlesMarkdownUploads;
use App\Models\Label;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use HandlesMarkdownUploads;

    #[Locked]
    public Project $project;

    public Module $module;

    #[Locked]
    public bool $canManage = false;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    public string $deadline = '';

    public string $deadlineReason = '';

    public bool $clientVisible = false;

    public array $assigneeIds = [];

    public array $labelIds = [];

    public string $newLabel = '';

    public string $newLabelColor = '#3b82f6';

    /** Budget inputs (major units), owner only. */
    public string $hours = '';

    public string $rate = '';

    public string $amount = '';

    public string $budgetReason = '';

    public string $commentBody = '';

    public ?int $replyTo = null;

    public string $replyBody = '';

    public function mount(Project $project, Module $module): void
    {
        $this->authorize('view', $project);
        abort_unless($module->project_id === $project->id, 404);
        $this->project = $project;
        $this->module = $module;
        $this->canManage = auth()->user()->can('update', $project);
    }

    protected function uploadTarget(): Model
    {
        $this->authorize('view', $this->project);

        return $this->module;
    }

    public function edit(): void
    {
        $this->authorize('update', $this->project);
        $m = $this->module;
        $this->name = $m->name;
        $this->description = $m->description ?? '';
        $this->deadline = $m->deadline?->toDateString() ?? '';
        $this->deadlineReason = '';
        $this->clientVisible = $m->client_visible;
        $this->assigneeIds = $m->assignees()->pluck('users.id')->all();
        $this->labelIds = $m->labels()->pluck('labels.id')->all();
        $this->hours = $m->budget_hours === null ? '' : (string) $m->budget_hours;
        $this->rate = $m->hourly_rate ?? '';
        $this->amount = $m->budget_amount ?? '';
        $this->budgetReason = '';
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function addLabel(): void
    {
        $this->authorize('update', $this->project);
        $this->validate(['newLabel' => 'required|string|max:50', 'newLabelColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $label = Label::firstOrCreate(['name' => trim($this->newLabel)], ['color' => $this->newLabelColor]);
        $this->labelIds = array_values(array_unique([...$this->labelIds, $label->id]));
        $this->reset('newLabel');
    }

    public function save(): void
    {
        $this->authorize('update', $this->project);
        $money = fn ($a, $v, $fail) => preg_match('/^\d{1,7}(\.\d{1,2})?$/', str_replace(',', '', (string) $v)) || $fail('Too large.');
        $this->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:20000',
            'deadline' => 'nullable|date',
            'deadlineReason' => 'nullable|string|max:1000',
            'clientVisible' => 'boolean',
            'assigneeIds' => 'array',
            'labelIds' => 'array',
            'hours' => ['bail', 'nullable', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            'rate' => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            'amount' => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            'budgetReason' => 'nullable|string|max:1000',
        ]);

        // budget first: a missing reason must stop the whole save
        if (auth()->user()->can('manage-money')) {
            $amt = fn ($v) => $v === '' ? null : Money::toInput(Money::toCents(str_replace(',', '', $v)));
            try {
                $this->module->updateBudget([
                    'budget_hours' => $this->hours === '' ? null : $this->hours,
                    'hourly_rate' => $amt($this->rate),
                    'budget_amount' => $amt($this->amount),
                ], auth()->user(), $this->budgetReason);
            } catch (ValidationException $e) {
                $this->addError('budgetReason', $e->errors()['reason'][0] ?? 'Reason required.');

                return;
            }
        }

        // only active project members can be assigned
        $valid = $this->candidates()->pluck('id')->all();
        $m = $this->module;
        $m->activityReason = $this->deadlineReason;
        $m->update([
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'deadline' => $this->deadline ?: null,
            'client_visible' => $this->clientVisible,
        ]);
        $m->syncAssignees(array_values(array_intersect(array_map('intval', $this->assigneeIds), $valid)));
        $m->syncLabels(Label::whereIn('id', $this->labelIds)->pluck('id')->all());

        $this->editing = false;
        $this->module = $m->fresh();
    }

    /** Quick save from the inline editor shown while the description is empty. */
    public function saveDescription(): void
    {
        $this->authorize('update', $this->project);
        $this->validate(['description' => 'nullable|string|max:20000']);
        $this->module->update(['description' => trim($this->description) ?: null]);
    }

    public function markCompleted(): void
    {
        $this->authorize('update', $this->project);
        $this->module->setStatusOverride('completed');
    }

    public function putOnHold(): void
    {
        $this->authorize('update', $this->project);
        $this->module->setStatusOverride('on_hold');
    }

    public function reopen(): void
    {
        $this->authorize('update', $this->project);
        $this->module->setStatusOverride(null);
    }

    public function postComment(): void
    {
        $this->authorize('view', $this->project);
        $this->validate(['commentBody' => 'required|string|max:10000']);
        $this->module->addComment(auth()->user(), $this->commentBody);
        $this->reset('commentBody');
    }

    public function startReply(int $commentId): void
    {
        $this->replyTo = $commentId;
        $this->replyBody = '';
    }

    public function postReply(): void
    {
        $this->authorize('view', $this->project);
        $this->validate(['replyBody' => 'required|string|max:10000', 'replyTo' => 'required|integer']);
        $this->module->addComment(auth()->user(), $this->replyBody, $this->replyTo);
        $this->reset('replyBody', 'replyTo');
    }

    /** Active project members: who can be assigned. */
    protected function candidates(): Collection
    {
        return $this->project->members()->wherePivot('active', true)->orderBy('name')->get();
    }

    /** Comment threads, activity and budget changes as one list, newest first. */
    public function timeline(): Collection
    {
        $m = $this->module;
        $items = $m->commentThreads()->map(fn ($c) => ['at' => $c->created_at, 'kind' => 'comment', 'seq' => $c->id, 'm' => $c]);
        $items = $items->concat($m->activities()->with('user')->get()->map(fn ($a) => ['at' => $a->created_at, 'kind' => 'activity', 'seq' => $a->id, 'm' => $a]));
        if (auth()->user()->can('manage-money')) {
            $items = $items->concat($m->budgetChanges()->with('user')->get()->map(fn ($b) => ['at' => $b->created_at, 'kind' => 'budget', 'seq' => $b->id, 'm' => $b]));
        }

        return $items->sortByDesc(fn ($i) => [$i['at']?->getTimestamp() ?? 0, $i['seq']])->values();
    }

    public function render()
    {
        return view('livewire.modules.show', [
            'tasks' => $this->module->tasks()->visibleTo(auth()->user())->with('column')->orderBy('position')->get(),
            'timeline' => $this->timeline(),
            'candidates' => $this->editing ? $this->candidates() : collect(),
            'allLabels' => $this->editing ? Label::orderBy('name')->get() : collect(),
            'status' => $this->module->status(),
            'assignees' => $this->module->assignees()->orderBy('name')->get(),
            'labels' => $this->module->labels()->orderBy('name')->get(),
            'hourly' => $this->project->client->billing_mode === \App\Enums\BillingMode::Hourly,
        ]);
    }
}
