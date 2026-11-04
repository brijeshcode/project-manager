{{-- Sprint dates "Nov 3 – Nov 9" plus, for an active sprint, the days left coloured like a deadline (yellow 0-3 days, red when late). $sprint. --}}
@props(['sprint'])
@php
    $d = \App\Support\Look::deadline($sprint->ends_on);
    $cls = ['late' => 'font-medium text-rose-600 dark:text-rose-400', 'soon' => 'font-medium text-yellow-600 dark:text-yellow-400'][$d['state']] ?? 'text-slate-500';
    $left = $d['days'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-x-2 text-xs']) }}>
    <span class="text-slate-500" data-test="sprint-dates">{{ $sprint->starts_on->format('M j') }} – {{ $sprint->ends_on->format('M j') }}</span>
    @if ($sprint->isActive())
        <span class="{{ $cls }}" data-test="days-left" data-state="{{ $d['state'] }}">{{ $left < 0 ? abs($left).' '.Str::plural('day', abs($left)).' overdue' : ($left === 0 ? 'ends today' : $left.' '.Str::plural('day', $left).' left') }}</span>
    @endif
</span>
