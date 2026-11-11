<?php

namespace App\Livewire\Billing;

use App\Models\RecurringCharge;
use App\Services\LocalCurrency;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecurringShow extends Component
{
    #[Locked]
    public int $chargeId;

    public function mount(RecurringCharge $charge): void
    {
        $this->authorize('manage-money');
        $this->chargeId = $charge->id;
    }

    public function toggle(): void
    {
        $this->authorize('manage-money');
        $c = RecurringCharge::findOrFail($this->chargeId);
        $c->setActive(! $c->active, today());
        $this->dispatch('toast', type: 'success', message: $c->active ? 'Service resumed.' : 'Service paused.');
    }

    public function delete()
    {
        $this->authorize('manage-money');
        if (! RecurringCharge::findOrFail($this->chargeId)->deleteIfNeverInvoiced()) {
            $this->dispatch('toast', type: 'error', message: RecurringCharge::KEEP_HISTORY);

            return null;
        }
        session()->flash('toast', 'Service deleted.');

        return $this->redirectRoute('billing.recurring', navigate: true);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $charge = RecurringCharge::withUsage()->with(['client', 'project'])->findOrFail($this->chargeId);

        return view('livewire.billing.recurring-show', [
            'charge' => $charge,
            'local' => LocalCurrency::code(),
            'usages' => $charge->lines()->whereHas('invoice', fn ($i) => $i->where('status', '!=', 'void'))->with('invoice:id,number,status,issued_on')
                ->get()->sortByDesc(fn ($l) => $l->invoice->issued_on->toDateString().' '.$l->period)->values(),
            'pending' => $charge->lines()->whereNull('invoice_id')->orderBy('period')->get(['id', 'period', 'amount_cents']),
        ]);
    }
}
