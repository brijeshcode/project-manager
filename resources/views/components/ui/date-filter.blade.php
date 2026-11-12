{{-- Pairs with App\Livewire\Concerns\WithDateFilter. $period = activePeriod(); $label names the date ("Issued", "Paid"). --}}
@props(['label', 'from', 'to', 'period' => false])
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-2']) }}>
    <span class="text-sm font-medium text-slate-600 dark:text-gray-300">{{ $label }}</span>
    <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700" role="group" aria-label="{{ $label }} period">
        @foreach (['' => 'Any date', 'month' => 'This month', 'year' => 'This year', 'fy' => 'Financial year'] as $p => $text)
            @php $on = $p === '' ? ($from === '' && $to === '') : $period === $p; @endphp
            <button type="button" wire:click="setPeriod('{{ $p }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" @class(['border-l border-slate-200 px-3 py-1.5 text-sm font-medium transition first:border-l-0 dark:border-gray-700', 'bg-brand-600 text-white' => $on, 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300 dark:hover:bg-gray-800' => ! $on]) @if ($p !== '') data-test="period-{{ $p }}" @endif>{{ $text }}</button>
        @endforeach
    </div>
    <div class="flex items-center gap-2">
        <label for="from" class="sr-only">{{ $label }} from</label>
        <input wire:model.live="from" id="from" type="date" class="input w-auto dark:[color-scheme:dark]" />
        <span class="text-sm text-slate-400">to</span>
        <label for="to" class="sr-only">{{ $label }} to</label>
        <input wire:model.live="to" id="to" type="date" class="input w-auto dark:[color-scheme:dark]" />
    </div>
</div>
