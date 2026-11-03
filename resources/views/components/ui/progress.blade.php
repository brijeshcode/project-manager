{{-- Progress bar, green at 100%, blue otherwise. $pct is 0..100. --}}
@props(['pct' => 0, 'label' => true])
@php($pct = (int) round($pct))
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }} title="{{ $pct }}% done">
    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $pct >= 100, 'bg-blue-500' => $pct < 100]) style="width: {{ $pct }}%" data-pct="{{ $pct }}"></div></div>
    @if ($label)<span class="w-9 text-right text-xs font-semibold tabular-nums text-slate-600" data-test="progress-label">{{ $pct }}%</span>@endif
</div>
