{{-- Client-currency amount with the local-currency equivalent underneath (hidden for a same-currency client or when unavailable). --}}
@props(['cents', 'currency', 'local' => null, 'missing' => 0])

@php
    $code = \App\Services\LocalCurrency::code();
    $show = $local !== null && strtoupper($currency) !== $code && ($local > 0 || $missing === 0);
@endphp

<div {{ $attributes }}>
    <div>{{ \App\Support\Money::format($cents, $currency) }}</div>
    @if ($show)
        <div class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ \App\Support\Money::format($local, $code) }}</div>
    @endif
</div>
