<?php

namespace App\Livewire\Currencies;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public string $localCurrency = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        Currency::forCode(LocalCurrency::code()); // the local currency is always on the list
        $this->localCurrency = LocalCurrency::code();
    }

    /** Popup action: pick which currency is the local one. Refused once money has been recorded. */
    public function saveLocalCurrency(): void
    {
        $this->authorize('manage-money');
        $this->validate(['localCurrency' => ['required', 'regex:/\A[A-Z]{3}\z/', Rule::exists('currencies', 'code')->where('is_active', true)]]);

        try {
            LocalCurrency::set($this->localCurrency, auth()->user());
        } catch (\DomainException $e) {
            $this->localCurrency = LocalCurrency::code();
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', 'local-currency');
        $this->dispatch('toast', type: 'success', message: 'Local currency saved.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('manage-money');
        $c = Currency::findOrFail($id);
        if ($c->code === LocalCurrency::code()) {
            $this->dispatch('toast', type: 'error', message: 'The local currency must stay active.');

            return;
        }
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-money');
        $c = Currency::findOrFail($id);
        if ($c->code === LocalCurrency::code() || $this->usage($c->id) > 0) {
            $this->dispatch('toast', type: 'error', message: 'This currency is in use (or is the local currency), so it cannot be deleted. Deactivate it instead.');

            return;
        }
        $c->delete();
        $this->dispatch('toast', message: 'Currency deleted.');
    }

    private function usage(int $id): int
    {
        return Client::where('currency_id', $id)->count() + Project::where('currency_id', $id)->count()
            + Invoice::where('currency_id', $id)->count() + Payment::where('currency_id', $id)->count();
    }

    public function render()
    {
        $this->authorize('manage-money');
        $local = LocalCurrency::code();
        $rates = app(ExchangeRates::class)->activeAll()->keyBy('currency');
        $currencies = Currency::orderBy('code')->get()->each(function ($c) use ($local, $rates) {
            $c->is_local = $c->code === $local;
            $c->active_rate = $c->is_local ? '1' : ($rates[$c->code]->rate ?? null);
            $c->used = $this->usage($c->id);
            $c->history = $c->is_local ? collect() : app(ExchangeRates::class)->history($c->code);
        });

        return view('livewire.currencies.index', [
            'currencies' => $currencies, 'local' => $local,
            'locked' => LocalCurrency::isLocked(),
            'localOptions' => Currency::active()->orderBy('code')->pluck('code'),
        ]);
    }
}
