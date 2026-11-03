<?php

namespace App\Livewire\Modules;

use App\Livewire\Concerns\ItemPage;
use App\Models\Module;
use App\Models\Project;
use App\Services\ProgressService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use ItemPage;

    #[Locked]
    public Project $project;

    public Module $module;

    #[Locked]
    public bool $canManage = false;

    public string $deadline = '';

    public string $deadlineReason = '';

    public bool $clientVisible = false;

    /** Budget inputs (major units), owner only. */
    public string $hours = '';

    public string $rate = '';

    public string $amount = '';

    public string $budgetReason = '';

    public function mount(Project $project, Module $module): void
    {
        $this->authorize('view', $project);
        abort_unless($module->project_id === $project->id, 404);
        $this->project = $project;
        $this->module = $module;
        $this->canManage = auth()->user()->can('update', $project);
    }

    protected function item(): Model
    {
        return $this->module;
    }

    protected function itemProject(): Project
    {
        return $this->project;
    }

    protected function authorizeEdit(): void
    {
        $this->authorize('update', $this->project);
    }

    protected function titleField(): string
    {
        return 'name';
    }

    protected function fillPanel(string $panel): void
    {
        $m = $this->module;
        $this->deadline = $m->deadline?->toDateString() ?? '';
        $this->deadlineReason = '';
        $this->clientVisible = $m->client_visible;
        $this->hours = $m->budget_hours === null ? '' : (string) $m->budget_hours;
        $this->rate = $m->hourly_rate ?? '';
        $this->amount = $m->budget_amount ?? '';
        $this->budgetReason = '';
    }

    protected function saveExtra(string $panel): void
    {
        $m = $this->module;
        if ($panel === 'deadline') {
            $this->validate(['deadline' => 'nullable|date', 'deadlineReason' => 'nullable|string|max:1000']);
            $m->activityReason = $this->deadlineReason;
            $m->update(['deadline' => $this->deadline ?: null]);
        } elseif ($panel === 'visibility') {
            $m->update(['client_visible' => $this->clientVisible]);
        } elseif ($panel === 'budget') {
            abort_unless(auth()->user()->can('manage-money'), 403);
            $money = fn ($a, $v, $fail) => preg_match('/^\d{1,7}(\.\d{1,2})?$/', str_replace(',', '', (string) $v)) || $fail('Too large.');
            $this->validate([
                'hours' => ['bail', 'nullable', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
                'rate' => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
                'amount' => ['bail', 'nullable', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
                'budgetReason' => 'nullable|string|max:1000',
            ]);
            $amt = fn ($v) => $v === '' ? null : Money::toInput(Money::toCents(str_replace(',', '', $v)));
            try {
                $m->updateBudget([
                    'budget_hours' => $this->hours === '' ? null : $this->hours,
                    'hourly_rate' => $amt($this->rate),
                    'budget_amount' => $amt($this->amount),
                ], auth()->user(), $this->budgetReason);
            } catch (ValidationException $e) {
                $this->addError('budgetReason', $e->errors()['reason'][0] ?? 'Reason required.');
            }
        }
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

    protected function extraTimeline(): Collection
    {
        if (! auth()->user()->can('manage-money')) {
            return collect();
        }

        return $this->module->budgetChanges()->with('user')->get()->map(fn ($b) => ['at' => $b->created_at, 'kind' => 'budget', 'seq' => $b->id, 'm' => $b]);
    }

    public function render()
    {
        return view('livewire.modules.show', $this->panelData() + [
            'tasks' => $this->module->tasks()->visibleTo(auth()->user())->with(['column', 'assignees', 'labels'])->orderBy('position')->get(),
            'progressService' => app(ProgressService::class),
            'copyUrl' => route('modules.create', ['project' => $this->project, 'copy' => $this->module->id]),
            'status' => $this->module->status(),
            'hourly' => $this->project->client->billing_mode === \App\Enums\BillingMode::Hourly,
        ]);
    }
}
