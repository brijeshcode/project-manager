<?php

namespace App\Livewire\Currencies;

use App\Models\Currency;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $currencyId = null;

    public string $code = '';
    public string $name = '';
    public string $rate = '';
    public bool $is_active = true;

    public function mount(?Currency $currency = null): void
    {
        $this->authorize('manage-money');
        if ($currency && $currency->exists) {
            $this->currencyId = $currency->id;
            $this->code = $currency->code;
            $this->name = $currency->name;
            $this->is_active = $currency->is_active;
        }
    }

    private function isLocal(): bool
    {
        return $this->code === LocalCurrency::code();
    }

    public function save()
    {
        $this->authorize('manage-money');
        $this->code = strtoupper(trim($this->code));
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['nullable', 'string'],
        ];
        if (! $this->currencyId) {
            $rules['code'] = ['required', 'regex:/\A[A-Z]{3}\z/', Rule::unique('currencies', 'code')];
        }
        $this->validate($rules, ['code.regex' => 'Use a 3-letter code such as USD.']);

        if ($this->currencyId) {
            $currency = Currency::findOrFail($this->currencyId);
            $this->code = $currency->code; // the code is never edited
            $currency->update(['name' => $this->name, 'is_active' => $this->isLocal() ? true : $this->is_active]);
        } else {
            $currency = Currency::create(['code' => $this->code, 'name' => $this->name, 'is_active' => $this->is_active]);
        }

        if ($this->rate !== '' && ! $this->isLocal()) {
            app(ExchangeRates::class)->setRate($currency->code, $this->rate, auth()->user());
        }
        session()->flash('toast', $this->currencyId ? 'Currency saved.' : 'Currency added.');

        return $this->redirectRoute('currencies.index');
    }

    public function render()
    {
        $this->authorize('manage-money');
        $active = $this->currencyId && ! $this->isLocal() ? app(ExchangeRates::class)->active($this->code) : null;

        return view('livewire.currencies.form', [
            'local' => LocalCurrency::code(),
            'isLocal' => $this->isLocal(),
            'activeRate' => $active === null ? null : rtrim(rtrim($active, '0'), '.'),
        ]);
    }
}
