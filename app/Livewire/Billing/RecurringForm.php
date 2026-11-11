<?php

namespace App\Livewire\Billing;

use App\Enums\RecurringFrequency;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecurringForm extends Component
{
    #[Locked]
    public ?int $chargeId = null;

    /** Client is fixed once the service has generated lines (they carry its client). */
    #[Locked]
    public bool $clientLocked = false;

    public ?int $client_id = null;
    public ?int $project_id = null;
    public string $description = '';
    public string $amount = '';
    public string $frequency = 'monthly';
    public string $next_due_on = '';

    public function mount(?RecurringCharge $charge = null): void
    {
        $this->authorize('manage-money');

        if ($charge?->exists) {
            $this->chargeId = $charge->id;
            $this->clientLocked = $charge->lines()->exists();
            $this->fill([
                'client_id' => $charge->client_id, 'project_id' => $charge->project_id, 'description' => $charge->description,
                'amount' => Money::toInput($charge->amount_cents), 'frequency' => $charge->frequency->value,
                'next_due_on' => $charge->next_due_on->toDateString(),
            ]);

            return;
        }

        $this->client_id = Client::whereKey((int) request()->query('client'))->value('id');
        $this->next_due_on = today()->toDateString();
    }

    public function updatedClientId(): void
    {
        $this->project_id = null;
    }

    public function save()
    {
        $this->authorize('manage-money');
        $charge = $this->chargeId ? RecurringCharge::findOrFail($this->chargeId) : null;
        if ($this->clientLocked) {
            $this->client_id = $charge->client_id;
        }

        $data = $this->validate([
            'client_id'   => 'required|exists:clients,id',
            'project_id'  => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'description' => 'required|string|max:255',
            'amount'      => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'frequency'   => ['required', new Enum(RecurringFrequency::class)],
            'next_due_on' => 'required|date_format:Y-m-d',
        ]);

        $attrs = [
            'client_id' => $data['client_id'], 'project_id' => $data['project_id'] ?: null,
            'description' => $data['description'], 'amount_cents' => Money::toCents($data['amount']),
            'frequency' => $data['frequency'], 'next_due_on' => $data['next_due_on'],
        ];

        if ($charge) {
            // a new due date re-anchors the day; a null rate is re-read so a currency change (project switch) is priced right
            $charge->update($attrs + ['due_day' => Carbon::parse($data['next_due_on'])->day, 'exchange_rate' => null]);
            session()->flash('toast', 'Service updated.');
        } else {
            $charge = RecurringCharge::create($attrs + ['active' => true]);
            session()->flash('toast', 'Service added.');
        }

        return $this->redirectRoute('billing.recurring.show', $charge, navigate: true);
    }

    public function render()
    {
        $this->authorize('manage-money');

        return view('livewire.billing.recurring-form', [
            'clients'  => Client::orderBy('name')->get(['id', 'name']),
            'projects' => $this->client_id ? Client::find($this->client_id)?->projects()->orderBy('name')->get(['id', 'name']) ?? collect() : collect(),
        ]);
    }
}
