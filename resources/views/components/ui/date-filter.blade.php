{{-- Pairs with App\Livewire\Concerns\WithDateFilter. $period = activePeriod(); $label names the date ("Issued", "Paid").
     $years (yearsSince()) adds a year dropdown; $year = activeYear(). $fromModel/$toModel bind other properties (ids prefixed $id).
     $defer: nothing is sent until the surrounding filter panel's Apply; shortcuts set the dates in the browser. --}}
@props(['label', 'from', 'to', 'period' => false, 'years' => [], 'year' => null, 'fromModel' => 'from', 'toModel' => 'to', 'id' => null, 'defer' => false])
@php
    $ids = $id ? fn ($n) => "$id-$n" : fn ($n) => $n;
    $ranges = ['' => ['', '']] + \App\Support\Periods::ranges();
    $names = ['' => 'Any date', 'month' => 'This month', 'year' => 'This year', 'fy' => 'Financial year'];
    $btn = 'border-l border-slate-200 px-3 py-1.5 text-sm font-medium transition first:border-l-0 dark:border-gray-700';
    $onCls = 'bg-brand-600 text-white';
    $offCls = 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-transparent dark:text-gray-300 dark:hover:bg-gray-800';
    $model = $defer ? 'wire:model' : 'wire:model.live';
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-2']) }}>
    <span class="text-sm font-medium text-slate-600 dark:text-gray-300">{{ $label }}</span>
    <div class="inline-flex overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700" role="group" aria-label="{{ $label }} period">
        @foreach ($names as $p => $text)
            @if ($defer)
                @php [$a, $b] = $ranges[$p]; @endphp
                <button type="button" x-on:click="$wire.{{ $fromModel }} = '{{ $a }}'; $wire.{{ $toModel }} = '{{ $b }}'"
                    :class="$wire.{{ $fromModel }} === '{{ $a }}' && $wire.{{ $toModel }} === '{{ $b }}' ? '{{ $onCls }}' : '{{ $offCls }}'"
                    class="{{ $btn }}" @if ($p !== '') data-test="{{ $ids('period') }}-{{ $p }}" @endif>{{ $text }}</button>
            @else
                @php $on = $p === '' ? ($from === '' && $to === '') : $period === $p; @endphp
                <button type="button" wire:click="setPeriod('{{ $p }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" @class([$btn, $onCls => $on, $offCls => ! $on]) @if ($p !== '') data-test="period-{{ $p }}" @endif>{{ $text }}</button>
            @endif
        @endforeach
    </div>
    @if ($years)
        <label for="{{ $ids('year') }}" class="sr-only">{{ $label }} year</label>
        <select id="{{ $ids('year') }}" class="input w-auto" data-test="year-filter"
            @if ($defer) x-on:change="const y = $event.target.value; $wire.{{ $fromModel }} = y ? y + '-01-01' : ''; $wire.{{ $toModel }} = y ? y + '-12-31' : ''"
            @else wire:change="setYear($event.target.value)" @endif>
            <option value="" @selected(! $year)>Year</option>
            @foreach ($years as $y)
                <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
            @endforeach
        </select>
    @endif
    <div class="flex items-center gap-2">
        <label for="{{ $ids('from') }}" class="sr-only">{{ $label }} from</label>
        <input {{ $model }}="{{ $fromModel }}" id="{{ $ids('from') }}" type="date" class="input w-auto dark:[color-scheme:dark]" />
        <span class="text-sm text-slate-400">to</span>
        <label for="{{ $ids('to') }}" class="sr-only">{{ $label }} to</label>
        <input {{ $model }}="{{ $toModel }}" id="{{ $ids('to') }}" type="date" class="input w-auto dark:[color-scheme:dark]" />
    </div>
</div>
