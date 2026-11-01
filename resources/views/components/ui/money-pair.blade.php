{{-- Client-currency amount with the local-currency equivalent underneath. Never shows a partial local total:
     with any missing rate it shows a note instead (hidden entirely for a same-currency client). --}}
@props(['cents', 'currency', 'local' => null, 'missing' => 0])

@php
    $code = \App\Services\LocalCurrency::code();
    $other = strtoupper($currency) !== $code;
@endphp

<div {{ $attributes }}>
    <div>{{ \App\Support\Money::format($cents, $currency) }}</div>
    @if ($other && $missing > 0)
        <div class="text-xs font-normal text-gray-500 dark:text-gray-400">Local total unavailable — {{ $missing }} without an exchange rate</div>
    @elseif ($other && $local !== null)
        <div class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ \App\Support\Money::format($local, $code) }}</div>
    @endif
</div>
