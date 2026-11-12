<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\WithListSearch;
use App\Models\BillableLine;
use App\Models\Client;
use App\Models\RecurringCharge;
use App\Services\LocalCurrency;
use App\Support\Money;
use App\Support\Settings;
use Livewire\Component;

class Recurring extends Component
{
    use WithListSearch;

    public string $clientFilter = '';

    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('manage-money');
    }

    public function updatedClientFilter(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->authorize('manage-money');
        $c = RecurringCharge::findOrFail($id);
        $c->setActive(! $c->active, today());
        $this->dispatch('toast', type: 'success', message: $c->active ? 'Service resumed.' : 'Service paused.');
    }

    public function bulk(string $action): void
    {
        $this->authorize('manage-money');
        abort_unless(in_array($action, ['skip', 'pause', 'resume'], true), 422);
        $charges = RecurringCharge::whereIn('id', array_map('intval', $this->selected))->get();
        $done = 0;
        foreach ($charges as $c) {
            $ok = match ($action) {
                'skip', 'pause' => $c->active,
                'resume' => ! $c->active && ! in_array($c->client_state, [RecurringCharge::ENDED, RecurringCharge::AWAITING], true),
            };
            if ($ok) {
                $action === 'skip' ? $c->skip(1) : $c->setActive($action === 'resume', today());
                $done++;
            }
        }
        $this->selected = [];
        $verb = ['skip' => 'skipped a period', 'pause' => 'paused', 'resume' => 'resumed'][$action];
        $this->dispatch('toast', type: $done ? 'success' : 'error', message: "{$done} of {$charges->count()} ".\Illuminate\Support\Str::plural('service', $charges->count())." {$verb}.");
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-money');
        $ok = RecurringCharge::findOrFail($id)->deleteIfNeverInvoiced();
        $this->dispatch('toast', type: $ok ? 'success' : 'error', message: $ok ? 'Service deleted.' : RecurringCharge::KEEP_HISTORY);
    }

    public function render()
    {
        $this->authorize('manage-money');
        $like = $this->likeTerm();

        $charges = RecurringCharge::query()->withUsage()->with(['client', 'project'])
            ->when($this->clientFilter !== '', fn ($q) => $q->where('client_id', (int) $this->clientFilter))
            ->when($this->status === 'active', fn ($q) => $q->where('active', true))
            ->when($this->status === 'paused', fn ($q) => $q->where('active', false))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw("description LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('client', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))
                ->orWhereHas('project', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]))))
            ->orderByDesc('active')->orderByRaw('CASE WHEN NOT active THEN COALESCE(ended_at, updated_at) END DESC')->orderBy('next_due_on');

        $active = RecurringCharge::with(['client', 'project'])->where('active', true)->get();
        $monthly = $active->reject->pass_through->groupBy(fn ($c) => $c->currency())->map->sum(fn ($c) => $c->monthlyCents()); // income only: pass-through costs left out
        $rates = $monthly->map(fn ($cents, $cur) => LocalCurrency::rateFor($cur));
        $invoiced = BillableLine::with(['client', 'project'])->whereNotNull('recurring_charge_id')->whereHas('invoice', fn ($i) => $i->where('status', 'sent'))->get();

        return view('livewire.billing.recurring', [
            'charges' => $this->fitPage($charges, Settings::pageSize()),
            'local' => LocalCurrency::code(),
            'clients' => Client::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'active' => $active->count(),
                'paused' => RecurringCharge::where('active', false)->count(),
                'monthly' => $monthly->all(),
                'monthly_local' => $rates->contains(null) ? null : $monthly->map(fn ($cents, $cur) => Money::convert($cents, $rates[$cur]))->sum(),
                'due_soon' => $active->filter(fn ($c) => $c->next_due_on->lte(today()->addDays(30)))->count(),
                'invoiced' => $invoiced->groupBy(fn ($l) => $l->lineCurrency() ?? LocalCurrency::code())->map->sum('amount_cents')->all(),
                'invoiced_local' => (int) $invoiced->sum('local_amount_cents'),
                'invoiced_count' => $invoiced->count(),
            ],
        ]);
    }
}
