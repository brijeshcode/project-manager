<?php

namespace App\Livewire\Concerns;

use App\Enums\PaymentType;
use App\Models\Client;
use App\Models\Currency;
use App\Services\ExchangeRates;
use App\Services\LocalCurrency;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Fields, validation and money math shared by the payment record form and the edit page.
 * A payment is amount + currency + rate + local amount; the amount is in the selected currency.
 */
trait PaymentFields
{
    public ?int $client_id = null;
    public ?int $project_id = null;
    public string $amount = '';
    public string $currency = '';
    public string $paid_on = '';
    public string $type = 'project';
    public string $method = '';
    public string $notes = '';
    public string $exchange_rate = '';

    public function updatedClientId(): void
    {
        $this->reset('project_id');
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $this->currency = $client ? strtoupper($client->currency) : '';
        $this->exchange_rate = $client ? self::tidy(LocalCurrency::rateFor($client->currency)) : '';
    }

    public function updatedCurrency(): void
    {
        $this->exchange_rate = $this->currency !== '' ? self::tidy(LocalCurrency::rateFor($this->currency)) : '';
    }

    /** 83.500000 -> 83.5, 1 stays 1, null -> empty. */
    private static function tidy(?string $r): string
    {
        return $r === null ? '' : (str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r);
    }

    private function rateRules(): array
    {
        return ['bail', 'required', 'string', fn ($a, $v, $fail) => Money::isValidRate((string) $v) || $fail('Enter a positive rate with up to 6 decimals (max 999999.999999).')];
    }

    /** Every active currency in the system, plus the local one and the client's own. */
    private function currencyOptions(?Client $client): array
    {
        return collect([LocalCurrency::code(), $client ? strtoupper($client->currency) : null])
            ->merge(Currency::active()->pluck('code'))
            ->filter()->unique()->sort()->values()->all();
    }

    private function effectiveCurrency(?Client $client): string
    {
        return strtoupper($this->currency !== '' ? $this->currency : ($client?->currency ?? ''));
    }

    /** Validate the form and return the data plus the computed money columns. */
    private function validatedPayment(): array
    {
        $client = $this->client_id ? Client::find($this->client_id) : null;
        $cur = $this->effectiveCurrency($client);
        $local = LocalCurrency::code();
        $data = $this->validate([
            'client_id' => 'required|exists:clients,id',
            'currency' => ['nullable', Rule::in($client ? $this->currencyOptions($client) : [])],
            'exchange_rate' => $client && $cur !== $local ? $this->rateRules() : 'nullable',
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('client_id', $this->client_id)],
            'amount' => ['bail', 'required', 'regex:/^\d{1,7}(\.\d{1,2})?$/', fn ($a, $v, $fail) => Money::toCents($v) > 0 || $fail('The amount must be greater than zero.')],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type' => ['required', new Enum(PaymentType::class)],
            'method' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $rate = $cur === $local ? '1' : $data['exchange_rate'];
        $cents = Money::toCents($data['amount']);

        return [
            'client_id' => $data['client_id'], 'project_id' => $data['project_id'] ?: null,
            'amount_cents' => $cents, 'currency' => $cur,
            'exchange_rate' => $rate, 'local_amount_cents' => Money::convert($cents, $rate), 'local_currency' => $local,
            'paid_on' => $data['paid_on'], 'type' => $data['type'],
            'method' => $data['method'] ?: null, 'notes' => $data['notes'] ?: null,
        ];
    }

    /** Live "≈ local" hint for the form; null when the inputs are not valid yet or the currency is local. */
    private function paymentPreview(?Client $client): ?string
    {
        $cur = $this->effectiveCurrency($client);
        if (! $client || $cur === LocalCurrency::code() || ! preg_match('/\A\d{1,7}(\.\d{1,2})?\z/', $this->amount) || ! Money::isValidRate($this->exchange_rate)) {
            return null;
        }

        return Money::format(Money::convert(Money::toCents($this->amount), $this->exchange_rate), LocalCurrency::code());
    }
}
