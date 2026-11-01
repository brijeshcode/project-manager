<?php

namespace App\Livewire\Settings;

use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use App\Support\Money;
use Livewire\Component;

class Index extends Component
{
    public const CODES = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'AUD', 'CAD', 'SGD', 'JPY'];

    public string $localCurrency = '';
    public string $currency = '';
    public string $rate = '';

    public function mount(): void
    {
        $this->authorize('manage-money');
        $this->localCurrency = LocalCurrency::code();
    }

    public function saveLocalCurrency(): void
    {
        $this->authorize('manage-money');
        $this->validate(['localCurrency' => 'bail|required|alpha|size:3']);

        try {
            LocalCurrency::set($this->localCurrency, auth()->user());
        } catch (\DomainException $e) {
            $this->localCurrency = LocalCurrency::code();
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $this->localCurrency = LocalCurrency::code();
        $this->dispatch('toast', type: 'success', message: 'Local currency saved.');
    }

    public function addRate(): void
    {
        $this->authorize('manage-money');
        $this->currency = strtoupper(trim($this->currency));

        $this->validate([
            'currency' => ['bail', 'required', 'alpha', 'size:3', fn ($a, $v, $fail) => $v === LocalCurrency::code() ? $fail('The local currency has an implied rate of 1.') : null],
            'rate' => ['bail', 'required', 'string',
                fn ($a, $v, $fail) => Money::isValidRate($v) ? null : $fail('Enter a positive rate with up to 6 decimals.'),
                fn ($a, $v, $fail) => (float) $v > 0 ? null : $fail('The rate must be greater than 0.'),
            ],
        ]);

        app(ExchangeRates::class)->setRate($this->currency, $this->rate, auth()->user());
        $this->reset('currency', 'rate');
        $this->dispatch('toast', type: 'success', message: 'Rate added.');
    }

    public function render()
    {
        $svc = app(ExchangeRates::class);
        $local = LocalCurrency::code();
        $active = $svc->activeAll();

        return view('livewire.settings.index', [
            'locked' => LocalCurrency::isLocked(),
            'local' => $local,
            'codes' => collect(self::CODES)->push($local)->unique()->values(),
            'rateCodes' => collect(self::CODES)->reject(fn ($c) => $c === $local)->values(),
            'active' => $active,
            'histories' => $active->mapWithKeys(fn ($r) => [$r->currency => $svc->history($r->currency)]),
        ]);
    }
}
