@php
    $e = $this->economics;
    $c = $e['currency'];
    $m = fn (?int $v) => $v === null ? '—' : \App\Support\Money::format($v, $c);
@endphp
<div>
    <div class="mb-6">
        <a href="{{ route('projects.show', $this->project) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-slate-700">&larr; {{ $this->project->name }}</a>
    </div>
    <h1 class="font-display text-2xl font-bold tracking-tight text-slate-900">Economics</h1>
    <p class="mt-1 text-sm text-slate-500">{{ $this->project->name }} &middot; {{ $c }}</p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.card><div class="text-sm text-slate-500">Hours logged</div><div class="text-2xl font-semibold">{{ rtrim(rtrim(number_format($e['hours'], 2, '.', ''), '0'), '.') }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Invoiced</div><div class="text-2xl font-semibold">{{ $m($e['invoiced_cents']) }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Received</div><div class="text-2xl font-semibold">{{ $m($e['received_cents']) }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Cost of logged hours</div><div class="text-2xl font-semibold">{{ $m($e['cost_cents']) }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Profit</div><div class="text-2xl font-semibold {{ $e['profit_cents'] < 0 ? 'text-red-600' : 'text-green-600' }}">{{ $m($e['profit_cents']) }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Effective hourly (received)</div><div class="text-2xl font-semibold">{{ $m($e['hourly_received_cents']) }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">Effective hourly (invoiced)</div><div class="text-2xl font-semibold">{{ $m($e['hourly_invoiced_cents']) }}</div></x-ui.card>
    </div>
</div>
