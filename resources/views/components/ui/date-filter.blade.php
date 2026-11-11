{{-- Pairs with App\Livewire\Concerns\WithDateFilter. $period = activePeriod(); $label names the date ("Issued", "Paid"). --}}
@props(['label', 'from', 'to', 'period' => false])
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
    <label for="from" class="text-sm text-slate-500 dark:text-gray-400">{{ $label }}</label>
    <input wire:model.live="from" id="from" type="date" class="input w-auto dark:[color-scheme:dark]" aria-label="{{ $label }} from" />
    <span class="text-sm text-slate-400">to</span>
    <input wire:model.live="to" id="to" type="date" class="input w-auto dark:[color-scheme:dark]" aria-label="{{ $label }} to" />
    @foreach (['month' => 'This month', 'year' => 'This year', 'fy' => 'This financial year'] as $p => $text)
        <button type="button" wire:click="setPeriod('{{ $p }}')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-brand-50 text-brand-700 dark:bg-blue-900/30 dark:text-blue-300' => $period === $p, 'text-slate-600 hover:bg-slate-100 dark:text-gray-300 dark:hover:bg-gray-700' => $period !== $p]) data-test="period-{{ $p }}">{{ $text }}</button>
    @endforeach
    @if ($from !== '' || $to !== '')
        <button type="button" wire:click="setPeriod('')" class="link text-sm">Any date</button>
    @endif
</div>
