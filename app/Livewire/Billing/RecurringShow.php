<?php

namespace App\Livewire\Billing;

use App\Models\RecurringCharge;
use App\Services\LocalCurrency;
use Carbon\Carbon;
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

    public int $skipCount = 1;

    public string $resumeOn = '';

    public function skip(): void
    {
        $this->authorize('manage-money');
        $this->validate(['skipCount' => 'required|integer|min:1|max:12']);
        $c = RecurringCharge::findOrFail($this->chargeId);
        abort_unless($c->active, 422);
        $c->skip($this->skipCount);
        $this->dispatch('toast', type: 'success', message: "Skipped {$this->skipCount} ".\Illuminate\Support\Str::plural('period', $this->skipCount).'. Next due '.$c->next_due_on->toFormattedDateString().'.');
    }

    public function pauseUntil(): void
    {
        $this->authorize('manage-money');
        $this->validate(['resumeOn' => 'required|date_format:Y-m-d|after:today'], [], ['resumeOn' => 'resume date']);
        $c = RecurringCharge::findOrFail($this->chargeId);
        $c->pauseUntil(Carbon::parse($this->resumeOn));
        $this->reset('resumeOn');
        $this->dispatch('toast', type: 'success', message: 'Paused until '.$c->resume_on->toFormattedDateString().'. It resumes on its own.');
    }

    public function decideCancel(bool $approve): void
    {
        $this->authorize('manage-money');
        RecurringCharge::findOrFail($this->chargeId)->decideCancel($approve);
        $this->dispatch('toast', type: 'success', message: $approve ? 'Subscription ended.' : 'Cancellation declined; the subscription continues.');
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
