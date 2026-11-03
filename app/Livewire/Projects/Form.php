<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Currency;
use App\Services\LocalCurrency;
use App\Support\BoardTemplates;
use App\Support\Money;
use Illuminate\Validation\Rule;
use App\Models\Project;
use Livewire\Component;

class Form extends Component
{
    public ?int $projectId = null;

    public int    $client_id      = 0;
    public string $currency       = '';   // defaults to the client's; the owner can change it
    public string $name           = '';
    public string $description    = '';
    public float  $buffer_percent = 0;
    public ?string $start_date    = null;
    public string $status         = 'draft';
    public ?string $aim           = null;
    public ?string $deadline      = null;
    public string $reason         = '';   // required when an existing deadline is changed
    public string $template      = 'software';   // board columns copied at create; edited later on the Board tab
    public string $initial_budget = '0';
    public string $addons_amount  = '0';

    public function mount(?Project $project = null): void
    {
        if ($project && $project->exists) {
            $this->authorize('update', $project);
        } else {
            $this->authorize('create', Project::class);
            $this->start_date = now()->toDateString();
        }

        // ?client=ID preselects the client on a new project (linked from the client pages)
        if (! ($project && $project->exists) && ($cid = (int) request()->query('client')) && Client::whereKey($cid)->exists()) {
            $this->client_id = $cid;
            $this->currency  = strtoupper((string) Client::find($cid)->currency);
        }

        if ($project && $project->exists) {
            $this->projectId     = $project->id;
            $this->client_id      = $project->client_id;
            $this->currency       = strtoupper((string) $project->currency);
            $this->name           = $project->name;
            $this->description    = $project->description ?? '';
            $this->buffer_percent = (float) $project->buffer_percent;
            $this->start_date     = $project->start_date?->toDateString();
            $this->status         = $project->status;
            $this->aim            = $project->aim;
            $this->deadline       = $project->deadline?->toDateString();
            $this->initial_budget = (string) $project->initial_budget;
            $this->addons_amount  = (string) $project->addons_amount;
        }
    }

    /** True when editing a project whose existing deadline was moved (needs a reason). */
    public function changingDeadline(): bool
    {
        $orig = $this->projectId ? Project::find($this->projectId)?->deadline?->toDateString() : null;

        return $orig && $this->deadline && $this->deadline !== $orig;
    }

    public function updatedClientId(): void
    {
        // a project starts in its client's currency
        $this->currency = ($c = Client::find($this->client_id)) ? strtoupper($c->currency) : '';
    }

    private function currencyOptions(): array
    {
        return collect([LocalCurrency::code(), ($c = Client::find($this->client_id)) ? strtoupper($c->currency) : null, $this->projectId ? Project::find($this->projectId)?->currency : null])
            ->merge(Currency::active()->pluck('code'))->filter()->unique()->sort()->values()->all();
    }

    public function save(): void
    {
        // commas are stripped, so bound the digits that remain (max 9,999,999.99)
        $money = fn ($a, $v, $fail) => preg_match('/^\d{1,7}(\.\d{1,2})?$/', str_replace(',', '', (string) $v)) || $fail('Too large.');
        $data = $this->validate([
            'aim'            => 'nullable|string|max:5000',
            'initial_budget' => ['bail', 'required', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            'addons_amount'  => ['bail', 'required', 'regex:/^[\d,]{1,11}(\.\d{1,2})?$/', $money],
            'currency'       => ['nullable', Rule::in($this->currencyOptions())],
            'client_id'      => 'required|exists:clients,id',
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string',
            'buffer_percent' => 'numeric|min:0|max:999.99',
            'start_date'     => 'nullable|date',
            'deadline'       => array_filter(['nullable', 'date', $this->start_date ? 'after_or_equal:start_date' : null]),
            'reason'         => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->changingDeadline())],
            'status'         => 'required|string|max:50',
            'template'       => ['required', Rule::in(array_keys(BoardTemplates::ALL))],
        ]);

        $data['aim'] = trim((string) $data['aim']) ?: null;
        $data['initial_budget'] = str_replace(',', '', $data['initial_budget']);
        $data['addons_amount'] = str_replace(',', '', $data['addons_amount']);
        $data['currency'] = $data['currency'] ?: null; // null on create = the client's currency
        ['deadline' => $deadline, 'reason' => $reason] = $data;
        unset($data['deadline'], $data['reason']);

        if ($this->projectId) {
            unset($data['template']);
            $project = Project::findOrFail($this->projectId);
            $this->authorize('update', $project);
            $project->update($data);
            $deadline && $project->setDeadline($deadline, auth()->user(), $reason);
        } else {
            $this->authorize('create', Project::class);
            $project = Project::create($data);
            $deadline && $project->setDeadline($deadline, auth()->user());
        }

        $this->redirect(route('projects.index'), navigate: true);
    }

    private function finalBudget(): string
    {
        $c = fn ($v) => preg_match('/^[\d,]{1,11}(\.\d{0,2})?$/', (string) $v) ? Money::toCents($v) : 0;

        return Money::toInput($c($this->initial_budget) + $c($this->addons_amount));
    }

    public function render()
    {
        return view('livewire.projects.form', [
            'clients' => Client::orderBy('name')->get(),
            'currencies' => $this->currencyOptions(),
            'templates' => array_keys(BoardTemplates::ALL),
            'finalBudget' => $this->finalBudget(),
        ]);
    }
}
