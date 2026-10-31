<?php

namespace App\Livewire\Billing;

use App\Enums\RecurringFrequency;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Component;

class Recurring extends Component
{
    public ?int $client_id = null;
    public ?int $project_id = null;
    public string $description = '';
    public string $amount = '';
    public string $frequency = 'monthly';
    public string $next_due_on = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function save(): void
    {
        $this->authorize('manage-money');

        $data = $this->validate([
            'client_id'   => 'required|exists:clients,id',
            'project_id'  => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'description' => 'required|string|max:255',
            'amount'      => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'frequency'   => ['required', new Enum(RecurringFrequency::class)],
            'next_due_on' => 'required|date_format:Y-m-d',
        ]);

        RecurringCharge::create([
            'client_id' => $data['client_id'], 'project_id' => $data['project_id'] ?: null,
            'description' => $data['description'], 'amount_cents' => Money::toCents($data['amount']),
            'frequency' => $data['frequency'], 'next_due_on' => $data['next_due_on'], 'active' => true,
        ]);

        $this->reset('project_id', 'description', 'amount', 'next_due_on');
    }

    public function toggle(int $id): void
    {
        $this->authorize('manage-money');
        $c = RecurringCharge::findOrFail($id);
        $c->setActive(! $c->active, today());
    }

    public function render()
    {
        return view('livewire.billing.recurring', [
            'charges'  => RecurringCharge::with(['client', 'project'])->orderByDesc('active')->orderBy('next_due_on')->get(),
            'clients'  => Client::orderBy('name')->get(),
            'projects' => $this->client_id ? Client::find($this->client_id)?->projects()->orderBy('name')->get() ?? collect() : collect(),
        ]);
    }
}
