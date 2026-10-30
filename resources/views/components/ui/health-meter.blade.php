{{-- Signature element: the two independent progress signals side by side.
     Checklist = work marked done; Time used = hours logged ÷ estimated.
     When the checklist is near full but time is over 100%, the time bar
     turns amber/rose — the early warning that a task is going sideways. --}}
@props(['checklist' => 0, 'time' => 0, 'compact' => false])

@php
    $c = max(0, min(100, round($checklist)));
    $t = (int) round($time);
    $tClamped = max(0, min(100, $t));
    $timeColor = $t > 100 ? 'bg-rose-500' : ($t >= 85 ? 'bg-amber-500' : 'bg-emerald-500');
    $timeText  = $t > 100 ? 'text-rose-600' : 'text-slate-900';
@endphp

<div class="{{ $compact ? 'space-y-2' : 'space-y-3' }}">
    <div>
        <div class="mb-1 flex items-center justify-between text-xs">
            <span class="font-medium text-slate-500">Checklist</span>
            <span class="font-semibold tabular-nums text-slate-900">{{ $c }}%</span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $c }}%"></div>
        </div>
    </div>
    <div>
        <div class="mb-1 flex items-center justify-between text-xs">
            <span class="font-medium text-slate-500">Time used</span>
            <span class="font-semibold tabular-nums {{ $timeText }}">{{ $t }}%</span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full {{ $timeColor }} transition-all" style="width: {{ $tClamped }}%"></div>
        </div>
    </div>
</div>
