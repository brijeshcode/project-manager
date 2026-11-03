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
    public string $code = '';
    public string $name = '';

    /** @var array<int,string> typed rate per currency id */
    public array $rate = [];
    /** @var array<int,string> inline name edits per currency id */
    public array $names = [];

    public function mount(): void
    {
        $this->authorize('manage-money');
        Currency::forCode(LocalCurrency::code()); // the local currency is always on the list
        $this->names = Currency::pluck('name', 'id')->all();
    }

    public function add(): void
    {
        $this->authorize('manage-money');
        $this->code = strtoupper(trim($this->code));
        $data = $this->validate([
            'code' => ['required', 'regex:/\A[A-Z]{3}\z/', Rule::unique('currencies', 'code')],
            'name' => ['required', 'string', 'max:100'],
        ], ['code.regex' => 'Use a 3-letter code such as USD.']);
        $c = Currency::create($data + ['is_active' => true]);
        $this->names[$c->id] = $c->name;
        $this->reset('code', 'name');
        $this->dispatch('toast', message: 'Currency added.');
    }

    public function rename(int $id): void
    {
        $this->authorize('manage-money');
        $c = Currency::findOrFail($id);
        $v = $this->validate(["names.{$id}" => ['required', 'string', 'max:100']])['names'][$id];
        $c->update(['name' => $v]);
        $this->dispatch('toast', message: 'Name saved.');
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

    public function setRate(int $id): void
    {
        $this->authorize('manage-money');
        $c = Currency::findOrFail($id);
        if ($c->code === LocalCurrency::code()) {
            return;
        }
        $v = $this->validate(["rate.{$id}" => ['required', 'string']])['rate'][$id];
        app(ExchangeRates::class)->setRate($c->code, $v, auth()->user());
        unset($this->rate[$id]);
        $this->dispatch('toast', message: 'Rate saved.');
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
        unset($this->names[$id]);
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
        });

        return view('livewire.currencies.index', ['currencies' => $currencies, 'local' => $local]);
    }
}
